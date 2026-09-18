"""The Google Places provider: an additional provider, through the CRM, in the OSM vocabulary.

Pinned here:

  - **No Google key in this process.** The provider asks the CRM, which holds the key and calls Google.
  - **Google is restricted to the area's OSM bounding box**, and a category with one exact Places type
    is searched by that type.
  - **Categories speak the OSM provider's vocabulary** (``hair_salon`` -> ``Hairdresser``), so the Nearby
    niche filter does not grow a synonym per provider.
  - **Identity is ``places/<id>``** — never an unprefixed key that could collide with OSM.
  - **A FAILED CALL IS NOT AN EMPTY RESULT.** Every failure carries an execution status.

No test touches the network: the CRM client and the OSM geocoder are both stubbed.
"""

import httpx
import pytest

from scraper.models import RawBusiness
from scraper.normalizer import normalize
from scraper.providers.google_places import (
    EXECUTION_AREA_UNRESOLVED,
    EXECUTION_CRM_UNREACHABLE,
    EXECUTION_INVALID_RESPONSE,
    EXECUTION_NOT_EFFECTIVE,
    EXECUTION_OK,
    INCLUDED_TYPES,
    MAX_PLANNED_QUERIES,
    GooglePlacesProvider,
    category_for,
)
from scraper.providers.openstreetmap import CATEGORY_SELECTORS

CRM = "http://crm.test/api/v1/crm"
BBOX = (14.52, 121.0, 14.58, 121.06)


class FakeOsm:
    def __init__(self, bbox=BBOX, fail: Exception | None = None) -> None:
        self.bbox = bbox
        self.fail = fail
        self.geocoded: list[str] = []
        self.closed = False

    def geocode_area(self, area):
        """The PUBLIC geocoding seam, mirroring the real provider.

        The double has to expose what its collaborator exposes: geocoding is a shared utility the
        partitioner and this provider both call by its public name, and a double still offering only
        the private one would pass while the real wiring was broken.
        """
        self.geocoded.append(area)
        if self.fail:
            raise self.fail
        return self.bbox

    def _geocode_area(self, area):
        return self.geocode_area(area)

    def close(self):
        self.closed = True


def row(provider_id="places/ChIJ-1", name="Mercury Drug - Makati", **extra):
    base = {
        "provider_id": provider_id,
        "name": name,
        "latitude": 14.5547,
        "longitude": 121.0244,
        "website": "https://www.mercurydrug.com/",
        "phone": "(02) 8911 5000",
        "city": "Makati City",
        "province": "Metro Manila",
        "region": "National Capital Region",
        "primary_type": "pharmacy",
        "types": ["pharmacy", "health", "point_of_interest", "establishment"],
    }
    return {**base, **extra}


def build(monkeypatch, *, respond, osm=None, use_strategy=False):
    fake_osm = osm or FakeOsm()
    # `use_strategy=False` by default: the optional AI planner is a SEPARATE capability with its own
    # tests below, and the deterministic assertions in this file must not depend on it — or on the
    # network it would otherwise reach. The tests that exercise it opt in explicitly.
    provider = GooglePlacesProvider(
        crm_base_url=CRM, crm_token="test-crm-token", timeout=5, osm=fake_osm, use_strategy=use_strategy
    )
    calls = []

    def post(url, **kwargs):
        calls.append((url, kwargs))
        return respond(url, kwargs)

    monkeypatch.setattr(provider._client, "post", post)
    return provider, fake_osm, calls


def ok(businesses, pages=1):
    return lambda url, kw: httpx.Response(
        200,
        json={"data": {"executionStatus": "ok", "errorReason": None, "businesses": businesses, "pages": pages}},
        request=httpx.Request("POST", url),
    )


# --- the request to the CRM ----------------------------------------------------------------------


def test_it_asks_the_crm_with_the_osm_bounding_box_and_the_places_type(monkeypatch):
    provider, osm, calls = build(monkeypatch, respond=ok([]))

    provider.discover(area="Makati City, Philippines", category="pharmacy", limit=10)

    assert osm.geocoded == ["Makati City, Philippines"]
    (url, kwargs), = calls
    assert url == f"{CRM}/discovery/places"
    assert kwargs["headers"]["Authorization"] == "Bearer test-crm-token"
    assert kwargs["json"] == {
        "area": "Makati City, Philippines",
        "category": "pharmacy",
        "limit": 10,
        "bbox": {"south": 14.52, "west": 121.0, "north": 14.58, "east": 121.06},
        "includedType": "pharmacy",
    }
    # No Google credential in the request, in any field.
    assert "key" not in str(kwargs["json"]).lower()


def test_a_category_without_one_exact_places_type_is_searched_by_text_only(monkeypatch):
    provider, _, calls = build(monkeypatch, respond=ok([]))

    provider.discover(area="Makati City, Philippines", category="salon", limit=10)

    assert "includedType" not in calls[0][1]["json"]


# --- the OPTIONAL AI planner ----------------------------------------------------------------------
#
# It may only ever ADD precision to a search this provider could already run. Google Places is an
# independent provider, so every AI outcome — absent, disabled, unreachable, out of credit — must
# leave it working exactly as it does with no AI at all.


def plans(monkeypatch, strategy):
    """Stub the strategy capability. `_strategy` imports it per call, so patching the module works.

    Returns the list the stub records its kwargs into, so a test can assert WHAT the planner was
    told — the target context is half of what this capability is for.
    """
    import scraper.strategy

    asked = []

    def fake(**kwargs):
        asked.append(kwargs)
        return strategy

    monkeypatch.setattr(scraper.strategy, "fetch_strategy", fake)
    return asked


def test_the_planner_receives_the_real_target_context(monkeypatch):
    # G1. A category word alone cannot produce a plan that suits an actual target: local trade names
    # differ by place, and breadth depends on how many businesses the run wants. Every value here
    # comes from the discovery task, so it is whatever this run is genuinely searching for.
    from scraper.strategy import DiscoveryStrategy

    asked = plans(monkeypatch, DiscoveryStrategy())
    provider, _, _ = build(monkeypatch, respond=ok([]), use_strategy=True)

    provider.discover(area="Some Municipality, Somewhere", category="bakery", limit=17)

    assert len(asked) == 1
    assert asked[0]["category"] == "bakery"
    assert asked[0]["scope"] == "Some Municipality, Somewhere"
    assert asked[0]["limit"] == 17


def test_the_planner_is_consulted_even_when_a_static_type_already_exists(monkeypatch):
    # G2. This previously asserted the OPPOSITE — the planner was skipped whenever the static table
    # had an entry, which meant it never ran for any configured target and the capability
    # contributed nothing. The product rule changed: AI ENHANCES a known category, it is not merely
    # a fallback for unmapped ones. Still one Google request.
    from scraper.strategy import DiscoveryStrategy

    asked = plans(monkeypatch, DiscoveryStrategy(queries=["24-hour drugstore"]))
    provider, _, calls = build(monkeypatch, respond=ok([]), use_strategy=True)

    provider.discover(area="Anywhere", category="pharmacy", limit=10)

    assert len(asked) == 1
    # ONE request to the CRM regardless of how many phrases it carries: the CRM decides how many
    # Google searches to run, and stops as soon as the limit is met.
    assert len(calls) == 1
    # The STATIC type still constrains what is matched — AI cannot weaken a proven mapping...
    assert calls[0][1]["json"]["includedType"] == "pharmacy"
    # ...and AI's contribution is the sharper phrasing for that same search.
    assert calls[0][1]["json"]["textQueries"] == ["24-hour drugstore"]


def test_ai_never_overrides_a_known_static_place_type(monkeypatch):
    from scraper.strategy import DiscoveryStrategy

    plans(monkeypatch, DiscoveryStrategy(place_types=["shopping_mall"], queries=["drugstore"]))
    provider, _, calls = build(monkeypatch, respond=ok([]), use_strategy=True)

    provider.discover(area="Anywhere", category="pharmacy", limit=10)

    # The deterministic baseline wins: a model that mis-typed the niche cannot redirect the search.
    assert calls[0][1]["json"]["includedType"] == "pharmacy"


def test_a_planned_type_and_query_shape_a_category_the_table_never_anticipated(monkeypatch):
    from scraper.strategy import DiscoveryStrategy

    plans(monkeypatch, DiscoveryStrategy(place_types=["hair_salon"], queries=["hair salon"]))
    provider, _, calls = build(monkeypatch, respond=ok([]), use_strategy=True)

    provider.discover(area="Anywhere", category="salon", limit=10)

    payload = calls[0][1]["json"]
    assert payload["includedType"] == "hair_salon"
    assert payload["textQueries"] == ["hair salon"]
    # The AREA is never taken from the plan — a planned query shapes WHAT is searched for, never WHERE.
    assert payload["area"] == "Anywhere"


def test_planned_queries_are_bounded(monkeypatch):
    # A query count is a multiplier on paid provider calls. However many the planner returns, only a
    # bounded few are ever sent — and the CRM enforces its own ceiling on top of this one.
    from scraper.strategy import DiscoveryStrategy

    plans(monkeypatch, DiscoveryStrategy(queries=[f"phrase {i}" for i in range(20)]))
    provider, _, calls = build(monkeypatch, respond=ok([]), use_strategy=True)

    provider.discover(area="Anywhere", category="salon", limit=10)

    sent = calls[0][1]["json"]["textQueries"]
    assert len(sent) == MAX_PLANNED_QUERIES
    assert sent == ["phrase 0", "phrase 1", "phrase 2"]


def test_the_run_records_planned_and_executed_query_counts(monkeypatch):
    # "Three phrases were planned" and "three searches were paid for" are different facts.
    from scraper.strategy import DiscoveryStrategy

    plans(monkeypatch, DiscoveryStrategy(queries=["a", "b", "c"]))
    provider, _, _ = build(
        monkeypatch,
        respond=lambda url, kw: httpx.Response(
            200,
            json={"data": {"executionStatus": "ok", "errorReason": None, "businesses": [row()],
                           "pages": 1, "queriesExecuted": 2}},
            request=httpx.Request("POST", url),
        ),
        use_strategy=True,
    )

    provider.discover(area="Anywhere", category="salon", limit=10)

    stats = provider.stats()
    assert stats["queries_planned"] == 3
    assert stats["queries_executed"] == 2


def test_an_unavailable_planner_leaves_the_text_only_search_untouched(monkeypatch):
    plans(monkeypatch, None)
    provider, _, calls = build(monkeypatch, respond=ok([]), use_strategy=True)

    provider.discover(area="Anywhere", category="salon", limit=10)

    payload = calls[0][1]["json"]
    assert "includedType" not in payload
    assert "textQueries" not in payload


def test_an_empty_plan_is_not_treated_as_advice(monkeypatch):
    from scraper.strategy import DiscoveryStrategy

    plans(monkeypatch, DiscoveryStrategy())
    provider, _, calls = build(monkeypatch, respond=ok([]), use_strategy=True)

    provider.discover(area="Anywhere", category="salon", limit=10)

    assert "includedType" not in calls[0][1]["json"]


def test_a_configured_category_still_works_unchanged_when_ai_is_unavailable(monkeypatch):
    # The fallback row that matters most: Google available, AI not. The static strategy must behave
    # exactly as it did before AI existed — no textQuery, the static type, one request.
    plans(monkeypatch, None)
    provider, _, calls = build(monkeypatch, respond=ok([]), use_strategy=True)

    provider.discover(area="Anywhere", category="pharmacy", limit=10)

    payload = calls[0][1]["json"]
    assert payload["includedType"] == "pharmacy"
    assert "textQueries" not in payload


def test_no_ai_value_can_become_a_business(monkeypatch):
    # THE DATA-INTEGRITY BOUNDARY. The planner returns a query and a type; the BUSINESSES come back
    # from Google. A plan cannot contribute an identity, a coordinate or a contact field — the only
    # thing it changed is which search ran.
    from scraper.strategy import DiscoveryStrategy

    plans(monkeypatch, DiscoveryStrategy(queries=["invented pharmacy"], place_types=["pharmacy"]))
    provider, _, _ = build(monkeypatch, respond=ok([row()]), use_strategy=True)

    (business,) = provider.discover(area="Anywhere", category="pharmacy", limit=10)

    assert business.provider_id == "places/ChIJ-1"
    assert business.name == "Mercury Drug - Makati"
    assert business.latitude == 14.5547
    # Nothing the planner said appears anywhere on the record.
    assert "invented" not in (business.name or "").lower()


def test_the_run_records_whether_ai_actually_shaped_the_search(monkeypatch):
    # "AI is enabled" and "AI influenced this query" are different facts, and only the second can be
    # judged after the fact.
    from scraper.strategy import DiscoveryStrategy

    plans(monkeypatch, DiscoveryStrategy(queries=["24-hour drugstore"]))
    provider, _, _ = build(monkeypatch, respond=ok([row()]), use_strategy=True)
    provider.discover(area="Anywhere", category="pharmacy", limit=10)
    assert provider.stats()["strategy_applied"] is True

    plans(monkeypatch, None)
    provider2, _, _ = build(monkeypatch, respond=ok([row()]), use_strategy=True)
    provider2.discover(area="Anywhere", category="pharmacy", limit=10)
    assert provider2.stats()["strategy_applied"] is False


def test_every_included_type_is_keyed_by_an_existing_osm_category():
    # The Places mapping must not invent a category the OSM provider does not know.
    assert set(INCLUDED_TYPES) <= set(CATEGORY_SELECTORS)


# --- mapping --------------------------------------------------------------------------------------


def test_rows_become_raw_businesses(monkeypatch):
    provider, _, _ = build(monkeypatch, respond=ok([row()]))

    (business,) = provider.discover(area="Makati City, Philippines", category="pharmacy", limit=10)

    assert business == RawBusiness(
        provider_id="places/ChIJ-1",
        name="Mercury Drug - Makati",
        latitude=14.5547,
        longitude=121.0244,
        website="https://www.mercurydrug.com/",
        phone="(02) 8911 5000",
        category="Pharmacy",
        region="National Capital Region",
        province="Metro Manila",
        city="Makati City",
    )


@pytest.mark.parametrize(
    ("primary", "types", "expected"),
    [
        ("pharmacy", [], "Pharmacy"),
        ("drugstore", [], "Pharmacy"),
        ("hair_salon", [], "Hairdresser"),
        ("barber_shop", [], "Hairdresser"),
        ("beauty_salon", [], "Beauty"),
        ("filipino_restaurant", ["restaurant"], "Restaurant"),
        ("fast_food_restaurant", [], "Fast Food"),
        ("dental_clinic", [], "Dentist"),
        ("fitness_center", [], "Fitness Centre"),
        ("real_estate_agency", [], "Estate Agent"),
        (None, ["point_of_interest", "establishment"], None),
        ("convenience_store", ["store"], "Convenience Store"),
    ],
)
def test_places_types_map_to_the_osm_vocabulary(primary, types, expected):
    assert category_for(primary, types) == expected


def test_unusable_rows_are_dropped_and_the_limit_is_honoured(monkeypatch):
    rows = [
        row("node/123", "Would Collide With OSM"),
        row("places/ChIJ-no-name", "  "),
        "not-a-row",
        row("places/ChIJ-a", "A"),
        row("places/ChIJ-b", "B"),
        row("places/ChIJ-c", "C"),
    ]
    provider, _, _ = build(monkeypatch, respond=ok(rows))

    found = provider.discover(area="Makati City, Philippines", category="pharmacy", limit=2)

    assert [b.provider_id for b in found] == ["places/ChIJ-a", "places/ChIJ-b"]


def test_missing_optional_fields_stay_empty(monkeypatch):
    bare = {"provider_id": "places/ChIJ-bare", "name": "Bare Pharmacy"}
    provider, _, _ = build(monkeypatch, respond=ok([bare]))

    (business,) = provider.discover(area="Makati City, Philippines", category="pharmacy", limit=5)

    assert business == RawBusiness(provider_id="places/ChIJ-bare", name="Bare Pharmacy")


def test_the_existing_normalizer_takes_google_records_unchanged(monkeypatch):
    provider, _, _ = build(monkeypatch, respond=ok([row()]))

    (business,) = provider.discover(area="Makati City, Philippines", category="pharmacy", limit=5)
    normalized = normalize(business, provider.name)

    assert normalized.place_id == "places/ChIJ-1"
    assert normalized.source == "google_places"
    assert normalized.industry == "Pharmacy"
    assert normalized.website_status == "website-unknown"
    assert (normalized.city, normalized.province, normalized.region) == ("Makati City", "Metro Manila", "National Capital Region")


# --- failures are never empty results --------------------------------------------------------------


def test_the_crm_refusing_because_google_is_not_effective_is_a_status(monkeypatch):
    provider, _, _ = build(
        monkeypatch,
        respond=lambda url, kw: httpx.Response(409, json={"data": {"businesses": []}}, request=httpx.Request("POST", url)),
    )

    assert provider.discover(area="Makati", category="pharmacy", limit=5) == []
    assert provider.stats()["execution_status"] == EXECUTION_NOT_EFFECTIVE


def test_an_upstream_google_failure_keeps_its_own_status(monkeypatch):
    provider, _, _ = build(
        monkeypatch,
        respond=lambda url, kw: httpx.Response(
            502,
            json={"data": {"executionStatus": "auth_failed", "errorReason": "page_1", "businesses": []}},
            request=httpx.Request("POST", url),
        ),
    )

    assert provider.discover(area="Makati", category="pharmacy", limit=5) == []
    assert provider.stats() == {"execution_status": "auth_failed", "error_reason": "page_1", "returned": 0}


def test_an_unreachable_crm_is_a_status(monkeypatch):
    def boom(url, kw):
        raise httpx.ConnectTimeout("timed out")

    provider, _, _ = build(monkeypatch, respond=boom)

    assert provider.discover(area="Makati", category="pharmacy", limit=5) == []
    assert provider.stats()["execution_status"] == EXECUTION_CRM_UNREACHABLE


def test_an_unreadable_payload_is_invalid_not_empty(monkeypatch):
    provider, _, _ = build(
        monkeypatch,
        respond=lambda url, kw: httpx.Response(200, json={"data": {"executionStatus": "ok"}}, request=httpx.Request("POST", url)),
    )

    assert provider.discover(area="Makati", category="pharmacy", limit=5) == []
    assert provider.stats()["execution_status"] == EXECUTION_INVALID_RESPONSE


def test_an_area_that_cannot_be_geocoded_never_reaches_the_crm(monkeypatch):
    provider, _, calls = build(monkeypatch, respond=ok([row()]), osm=FakeOsm(fail=ValueError("unresolved")))

    assert provider.discover(area="Nowhere", category="pharmacy", limit=5) == []
    assert provider.stats()["execution_status"] == EXECUTION_AREA_UNRESOLVED
    assert calls == []


def test_a_successful_run_reports_its_numbers(monkeypatch):
    provider, _, _ = build(monkeypatch, respond=ok([row("places/ChIJ-a", "A"), row("places/ChIJ-b", "B")], pages=1))

    provider.discover(area="Makati", category="pharmacy", limit=10)

    assert provider.stats() == {
        "execution_status": EXECUTION_OK,
        "error_reason": None,
        "returned": 2,
        "pages": 1,
        "included_type": "pharmacy",
        # The run now also records whether AI actually shaped the search. False here because this
        # provider was built with the planner off — still an exact, whole-shape assertion.
        "strategy_applied": False,
        # Planned vs paid for. Zero planned with the planner off, and the CRM reported no count
        # because this fake response predates the field — both are honest absences, not defaults.
        "queries_planned": 0,
        "queries_executed": None,
    }


def test_close_releases_the_crm_client_and_the_geocoder(monkeypatch):
    provider, osm, _ = build(monkeypatch, respond=ok([]))

    provider.close()

    assert osm.closed


def test_a_missing_crm_token_fails_loudly():
    with pytest.raises(ValueError, match="CRM_TOKEN is required"):
        GooglePlacesProvider(crm_base_url=CRM, crm_token="", timeout=5, osm=FakeOsm())


# --- the planner is OPT-IN (a Google-only run reaches no model) ------------------------------------


def test_a_google_only_run_consults_no_planner_by_default(monkeypatch):
    """The regression that matters: discovery reached the AI planner once per target without anyone
    asking for it, so a Google-only run made an outbound model call (and logged a 402) per search.
    The planner is a SEPARATE capability and must be requested, never implied."""
    from scraper.strategy import DiscoveryStrategy

    asked = plans(monkeypatch, DiscoveryStrategy(queries=["should never be asked for"]))
    # Constructed WITHOUT `use_strategy` on purpose — this asserts the constructor's own default,
    # which is what the provider factory relies on.
    provider = GooglePlacesProvider(
        crm_base_url=CRM, crm_token="test-crm-token", timeout=5, osm=FakeOsm()
    )
    calls = []

    def post(url, **kwargs):
        calls.append((url, kwargs))
        return ok([])(url, kwargs)

    monkeypatch.setattr(provider._client, "post", post)

    provider.discover(area="Bacolod City, Philippines", category="bakery", limit=3)

    # No planner call at all...
    assert asked == []
    # ...and Google Places still ran: one factual search, unchanged.
    assert len(calls) == 1
    assert calls[0][1]["json"]["includedType"] is not None or calls[0][1]["json"]["textQueries"]


def test_the_planner_remains_available_when_explicitly_requested(monkeypatch):
    """The AI architecture is preserved, not deleted — the future AI-enabled flow opts in and the
    planner works exactly as before."""
    from scraper.strategy import DiscoveryStrategy

    asked = plans(monkeypatch, DiscoveryStrategy(queries=["artisan sourdough bakery"]))
    provider, _, calls = build(monkeypatch, respond=ok([]), use_strategy=True)

    provider.discover(area="Bacolod City, Philippines", category="bakery", limit=3)

    assert len(asked) == 1
    assert calls[0][1]["json"]["textQueries"] == ["artisan sourdough bakery"]


def test_the_planning_status_travels_with_the_stats_only_when_planning_was_consulted(monkeypatch):
    # Per-run AI Discovery: the CRM must be able to tell "no credit" from "AI switched off", and a
    # Google-only run (planning off) must report nothing about planning at all.
    import scraper.strategy

    def refused(**kwargs):
        kwargs["report"]["status"] = "insufficient_credit"
        return None

    monkeypatch.setattr(scraper.strategy, "fetch_strategy", refused)
    provider, _, _ = build(monkeypatch, respond=ok([row()]), use_strategy=True)
    provider.discover(area="Anywhere", category="pharmacy", limit=10)
    stats = provider.stats()
    assert stats["strategy_status"] == "insufficient_credit"
    assert stats["strategy_applied"] is False

    plain, _, _ = build(monkeypatch, respond=ok([row()]), use_strategy=False)
    plain.discover(area="Anywhere", category="pharmacy", limit=10)
    assert "strategy_status" not in plain.stats()
