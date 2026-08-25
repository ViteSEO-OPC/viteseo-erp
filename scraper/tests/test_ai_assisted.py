"""The composite provider — AI ADDS to the OpenStreetMap baseline and can never subtract from it.

Every test here is about one promise: whatever the AI leg does — succeeds, fails, times out, raises,
returns nothing, returns duplicates — a run must never return less than the plain OpenStreetMap
provider would have returned on its own.

No network: both lanes are injected.
"""

import pytest

from scraper.models import RawBusiness
from scraper.normalizer import normalize
from scraper.providers.ai_assisted import (
    EXPANSION_UNSUPPORTED,
    LANE_AI,
    LANE_BASELINE,
    SKIPPED_BASELINE_FULL,
    AiAssistedDiscoveryProvider,
)
from scraper.providers.openstreetmap import SelectorSweep


def business(name: str, provider_id: str) -> RawBusiness:
    return RawBusiness(provider_id=provider_id, name=name, latitude=14.5, longitude=121.0)


class FakeOsm:
    """Stands in for OpenStreetMapProvider — both the default sweep and the selector sweep."""

    def __init__(
        self,
        *found: RawBusiness,
        sweep: SelectorSweep | None = None,
        sweep_raises: Exception | None = None,
    ) -> None:
        self._found = list(found)
        self._sweep = sweep
        self._sweep_raises = sweep_raises
        self.calls: list[dict] = []
        self.selector_calls: list[list[tuple[str, str]]] = []

    def discover(self, *, area: str, category: str, limit: int) -> list[RawBusiness]:
        self.calls.append({"area": area, "category": category, "limit": limit})
        return [b.model_copy(deep=True) for b in self._found][:limit]

    def find_by_selectors(self, selectors, *, area: str, limit: int = 200) -> SelectorSweep:
        self.selector_calls.append(list(selectors))
        if self._sweep_raises is not None:
            raise self._sweep_raises
        if self._sweep is None:
            return SelectorSweep()
        return SelectorSweep(
            businesses=[b.model_copy(deep=True) for b in self._sweep.businesses],
            by_selector={k: list(v) for k, v in self._sweep.by_selector.items()},
        )



class FakeAi:
    """Stands in for the AI leg. Can return records, report a failure status, or raise."""

    def __init__(self, *found: RawBusiness, stats: dict | None = None, raises: Exception | None = None) -> None:
        self._found = list(found)
        self._stats = stats or {"execution_status": "ok", "proposed": len(found), "resolved": len(found)}
        self._raises = raises
        self.called = False
        self.closed = False

    def discover(self, *, area: str, category: str, limit: int) -> list[RawBusiness]:
        self.called = True
        if self._raises is not None:
            raise self._raises
        return [b.model_copy(deep=True) for b in self._found][:limit]

    def stats(self) -> dict:
        return dict(self._stats)

    def close(self) -> None:
        self.closed = True
class FakeAiWithTags(FakeAi):
    """An AI leg that also offers tag expansion."""

    def __init__(self, *found: RawBusiness, selectors=None, status="ok", error=None,
                 raises_expand: Exception | None = None, **kw) -> None:
        super().__init__(*found, **kw)
        self._selectors = list(selectors or [])
        self._expand_status = status
        self._expand_error = error
        self._raises_expand = raises_expand
        self.expand_calls: list[tuple] = []

    def expand_tags(self, category: str, known):
        self.expand_calls.append((category, list(known)))
        if self._raises_expand is not None:
            raise self._raises_expand
        return (self._selectors, self._expand_status, self._expand_error,
                {"model": "mock", "prompt_tokens": 0, "completion_tokens": 0})


def composite(osm: FakeOsm, ai: FakeAi) -> AiAssistedDiscoveryProvider:
    return AiAssistedDiscoveryProvider(osm=osm, ai=ai)


# --- the core promise: AI adds, never replaces ------------------------------------


def test_the_osm_baseline_always_runs_and_is_returned():
    osm = FakeOsm(business("Vigan Pharmacy", "node/1"))
    provider = composite(osm, FakeAi())

    result = provider.discover(area="Vigan", category="pharmacy", limit=30)

    assert osm.calls == [{"area": "Vigan", "category": "pharmacy", "limit": 30}]
    assert [b.provider_id for b in result] == ["node/1"]


def test_ai_findings_are_added_on_top_of_the_baseline():
    osm = FakeOsm(business("Vigan Pharmacy", "node/1"))
    ai = FakeAi(business("Mercury Drug Vigan", "node/2"))
    provider = composite(osm, ai)

    result = provider.discover(area="Vigan", category="pharmacy", limit=30)

    assert [b.provider_id for b in result] == ["node/1", "node/2"]
    assert provider.stats()["net_new"] == 1


def test_a_business_both_lanes_found_is_counted_once():
    # The whole reason provider_id is the dedup contract: the AI leg resolves through OSM, so a
    # business the tag sweep already returned arrives under the SAME key and is one business.
    osm = FakeOsm(business("Mercury Drug Vigan", "node/1"))
    ai = FakeAi(business("Mercury Drug Vigan", "node/1"))
    provider = composite(osm, ai)

    result = provider.discover(area="Vigan", category="pharmacy", limit=30)

    assert [b.provider_id for b in result] == ["node/1"]
    assert provider.stats()["ai_duplicates"] == 1
    assert provider.stats()["net_new"] == 0
    # It stays a BASELINE record — the sweep found it first and the AI leg added nothing to it.
    assert result[0].discovered_by == LANE_BASELINE


# --- AI failure must never reduce the default result ------------------------------


def test_an_ai_failure_status_leaves_the_baseline_intact():
    osm = FakeOsm(business("Vigan Pharmacy", "node/1"), business("Rose Pharmacy", "node/2"))
    ai = FakeAi(stats={"execution_status": "auth_failed", "error_reason": "http_401"})
    provider = composite(osm, ai)

    result = provider.discover(area="Vigan", category="pharmacy", limit=30)

    assert [b.provider_id for b in result] == ["node/1", "node/2"]
    assert provider.stats()["ai_execution_status"] == "auth_failed"


def test_an_ai_exception_leaves_the_baseline_intact():
    # The resolver reaches Overpass and Nominatim, which fail in ways the AI leg does not model. The
    # guarantee must not depend on it being well-behaved.
    osm = FakeOsm(business("Vigan Pharmacy", "node/1"))
    ai = FakeAi(raises=RuntimeError("Overpass is rate-limiting"))
    provider = composite(osm, ai)

    result = provider.discover(area="Vigan", category="pharmacy", limit=30)

    assert [b.provider_id for b in result] == ["node/1"]
    assert provider.stats()["ai_execution_status"] == "provider_errored"
    assert provider.stats()["ai_error_reason"] == "RuntimeError"


@pytest.mark.parametrize("ai", [
    FakeAi(stats={"execution_status": "crm_unreachable"}),
    FakeAi(raises=ValueError("boom")),
    FakeAi(),
])
def test_a_failed_ai_leg_is_never_a_failed_run(ai):
    # run_discovery exits 3 on a non-ok execution_status. That is right when the provider IS the run;
    # here the run genuinely succeeded and delivered the baseline, so the COMPOSITE reports ok and
    # the AI leg's real verdict travels separately.
    provider = composite(FakeOsm(business("Vigan Pharmacy", "node/1")), ai)

    provider.discover(area="Vigan", category="pharmacy", limit=30)

    assert provider.stats()["execution_status"] == "ok"


def test_an_ai_leg_that_finds_nothing_is_not_an_error():
    provider = composite(FakeOsm(business("Vigan Pharmacy", "node/1")), FakeAi())

    result = provider.discover(area="Vigan", category="pharmacy", limit=30)

    assert len(result) == 1
    assert provider.stats()["ai_execution_status"] == "ok"
    assert provider.stats()["net_new"] == 0


# --- not paying for what the baseline already covered -----------------------------


def test_the_model_is_not_called_when_the_baseline_already_fills_the_limit():
    osm = FakeOsm(business("A", "node/1"), business("B", "node/2"))
    ai = FakeAi(business("C", "node/3"))
    provider = composite(osm, ai)

    result = provider.discover(area="Vigan", category="pharmacy", limit=2)

    assert ai.called is False
    assert len(result) == 2
    assert provider.stats()["ai_skipped"] == SKIPPED_BASELINE_FULL
    assert provider.stats()["execution_status"] == "ok"


def test_the_model_is_called_when_the_baseline_falls_short():
    osm = FakeOsm(business("A", "node/1"))
    ai = FakeAi(business("C", "node/3"))
    provider = composite(osm, ai)

    provider.discover(area="Vigan", category="pharmacy", limit=10)

    assert ai.called is True
    assert provider.stats()["ai_skipped"] is None


# --- provenance -------------------------------------------------------------------


def test_each_record_carries_the_lane_that_found_it():
    osm = FakeOsm(business("Vigan Pharmacy", "node/1"))
    ai = FakeAi(business("Mercury Drug Vigan", "node/2"))
    provider = composite(osm, ai)

    result = provider.discover(area="Vigan", category="pharmacy", limit=30)
    lanes = {b.provider_id: b.discovered_by for b in result}

    assert lanes == {"node/1": LANE_BASELINE, "node/2": LANE_AI}


def test_provenance_survives_normalization_into_the_crm_payload():
    # `source` is what the CRM cohorts on. Without this the AI leg's contribution is unmeasurable.
    osm = FakeOsm(business("Vigan Pharmacy", "node/1"))
    ai = FakeAi(business("Mercury Drug Vigan", "node/2"))
    provider = composite(osm, ai)

    rows = [normalize(b, provider.name) for b in provider.discover(area="Vigan", category="pharmacy", limit=30)]

    assert {r.place_id: r.source for r in rows} == {
        "node/1": "openstreetmap",
        "node/2": "openai",
    }


def test_the_baseline_lane_keeps_its_historical_source_value():
    # Existing leads are stored under `openstreetmap`. A new value would split the cohort in two and
    # make every previously discovered lead look like it came from somewhere else.
    assert LANE_BASELINE == "openstreetmap"


# --- bounds and teardown ----------------------------------------------------------


def test_the_callers_limit_still_binds_and_the_baseline_is_spent_first():
    osm = FakeOsm(business("A", "node/1"), business("B", "node/2"))
    ai = FakeAi(business("C", "node/3"), business("D", "node/4"))
    provider = composite(osm, ai)

    result = provider.discover(area="Vigan", category="pharmacy", limit=3)

    # Deterministic lane first, expansion fills what is left.
    assert [b.provider_id for b in result] == ["node/1", "node/2", "node/3"]


def test_closing_releases_the_ai_leg():
    ai = FakeAi()
    composite(FakeOsm(), ai).close()

    assert ai.closed is True


def test_stats_are_a_copy_and_cannot_be_mutated_from_outside():
    provider = composite(FakeOsm(business("A", "node/1")), FakeAi())
    provider.discover(area="Vigan", category="pharmacy", limit=30)

    provider.stats()["net_new"] = 999

    assert provider.stats()["net_new"] == 0


# --- same-name businesses keep distinct identities all the way to the CRM ---------


def test_two_same_named_branches_reach_the_crm_as_two_distinct_leads():
    # THE WATSONS CASE, END TO END. Two OSM nodes both called "Watsons" are two real branches. They
    # must arrive as two leads with two different place_ids — the value the CRM dedups on — not as
    # one lead, and not as two leads sharing a key.
    osm = FakeOsm(business("Vigan Pharmacy", "node/1"))
    ai = FakeAi(business("Watsons", "node/2"), business("Watsons", "node/3"))
    provider = composite(osm, ai)

    rows = [normalize(b, provider.name) for b in provider.discover(area="Vigan", category="pharmacy", limit=30)]
    place_ids = [r.place_id for r in rows]

    assert place_ids == ["node/1", "node/2", "node/3"]
    assert len(set(place_ids)) == len(place_ids)
    assert provider.stats()["net_new"] == 2


def test_a_same_named_branch_the_baseline_already_had_is_still_deduped():
    # Relaxing the ambiguity rule must not weaken dedup. Identity is the KEY, never the name — one of
    # these two "Watsons" is already in the baseline and must not arrive twice.
    osm = FakeOsm(business("Watsons", "node/2"))
    ai = FakeAi(business("Watsons", "node/2"), business("Watsons", "node/3"))
    provider = composite(osm, ai)

    result = provider.discover(area="Vigan", category="pharmacy", limit=30)

    assert [b.provider_id for b in result] == ["node/2", "node/3"]
    assert provider.stats()["ai_duplicates"] == 1
    assert provider.stats()["net_new"] == 1


def test_same_named_branches_keep_their_own_lane_and_coordinates():
    # Each record carries its OWN OSM identity. Nothing is merged, copied between branches, or
    # inferred from the shared name.
    baseline = business("Watsons", "node/2")
    baseline.latitude, baseline.longitude = 17.57, 120.38
    extra = business("Watsons", "node/3")
    extra.latitude, extra.longitude = 17.58, 120.39
    provider = composite(FakeOsm(baseline), FakeAi(extra))

    result = provider.discover(area="Vigan", category="pharmacy", limit=30)
    by_id = {b.provider_id: b for b in result}

    assert by_id["node/2"].discovered_by == LANE_BASELINE
    assert by_id["node/3"].discovered_by == LANE_AI
    assert by_id["node/2"].latitude != by_id["node/3"].latitude


def test_rediscovering_the_same_pool_is_idempotent():
    # Re-running must produce the SAME keys, or the CRM would treat every re-run as new businesses.
    def run():
        provider = composite(
            FakeOsm(business("Watsons", "node/2")),
            FakeAi(business("Watsons", "node/3"), business("Mercury Drug", "node/4")),
        )
        return [r.place_id for r in
                (normalize(b, provider.name) for b in
                 provider.discover(area="Vigan", category="pharmacy", limit=30))]

    assert run() == run() == ["node/2", "node/3", "node/4"]


def test_a_keyless_record_never_becomes_a_lead_without_a_place_id():
    # The CRM dedups on place_id; a lead without one can never be matched against itself. The
    # resolver refuses these upstream, and this pins the consequence if one ever got through.
    from scraper.providers.osm_resolver import OsmNameResolver

    outcome = OsmNameResolver(
        lambda names, area: [business("Watsons", "")]
    ).resolve(["Watsons"], area="Vigan")

    assert outcome.resolved == []


# --- lane 2: AI-expanded tag sweep ------------------------------------------------


def tag_sweep(*pairs) -> SelectorSweep:
    """(selector_label, RawBusiness) pairs -> a SelectorSweep with attribution."""
    sweep = SelectorSweep()
    for label, biz in pairs:
        sweep.businesses.append(biz)
        sweep.by_selector.setdefault(label, []).append(biz.provider_id)
    return sweep


def test_tag_expansion_adds_businesses_the_default_sweep_cannot_reach():
    # THE MEASURED CASE. `amenity=restaurant` structurally cannot return an `amenity=fast_food`
    # outlet, no matter how good the matching is — the tag is never queried.
    osm = FakeOsm(
        business("Cafe Leona", "node/1"),
        sweep=tag_sweep(("amenity=fast_food", business("Mang Inasal", "node/2"))),
    )
    ai = FakeAiWithTags(selectors=["amenity=fast_food"])
    provider = composite(osm, ai)

    result = provider.discover(area="Vigan", category="restaurant", limit=30)

    assert [b.provider_id for b in result] == ["node/1", "node/2"]
    assert provider.stats()["expansion_net_new"] == 1
    assert provider.stats()["expansion_selectors"] == ["amenity=fast_food"]


def test_the_expander_is_told_what_the_baseline_already_sweeps():
    osm = FakeOsm(business("Cafe Leona", "node/1"))
    ai = FakeAiWithTags(selectors=[])
    composite(osm, ai).discover(area="Vigan", category="restaurant", limit=30)

    category, known = ai.expand_calls[0]
    assert category == "restaurant"
    assert ("amenity", "restaurant") in known


def test_a_rejected_selector_is_never_swept():
    # Validation stands between the model and Overpass. A car-park selector must not reach the query.
    osm = FakeOsm(business("Cafe Leona", "node/1"))
    ai = FakeAiWithTags(selectors=["amenity=parking", "building=yes"])
    provider = composite(osm, ai)

    provider.discover(area="Vigan", category="restaurant", limit=30)

    assert osm.selector_calls == []          # no sweep was attempted at all
    assert provider.stats()["expansion_rejected"] == 2
    assert provider.stats()["expansion_net_new"] == 0


def test_expansion_records_per_selector_attribution():
    # A selector that only returns businesses the baseline already held earns nothing and should be
    # pruned. Measured on live data, `healthcare=pharmacy` is exactly that.
    already = business("Rose Pharmacy", "node/1")
    osm = FakeOsm(
        already,
        sweep=tag_sweep(
            ("shop=chemist", business("Watsons", "node/2")),
            ("healthcare=pharmacy", already.model_copy(deep=True)),
        ),
    )
    ai = FakeAiWithTags(selectors=["shop=chemist", "healthcare=pharmacy"])
    provider = composite(osm, ai)

    provider.discover(area="Vigan", category="pharmacy", limit=30)
    per = provider.stats()["expansion_by_selector"]

    assert per["shop=chemist"] == {"found": 1, "net_new": 1}
    assert per["healthcare=pharmacy"] == {"found": 1, "net_new": 0}
    assert provider.stats()["expansion_duplicates"] == 1


def test_expansion_findings_carry_the_ai_lane():
    osm = FakeOsm(
        business("Cafe Leona", "node/1"),
        sweep=tag_sweep(("amenity=fast_food", business("Mang Inasal", "node/2"))),
    )
    provider = composite(osm, FakeAiWithTags(selectors=["amenity=fast_food"]))

    rows = [normalize(b, provider.name) for b in provider.discover(area="Vigan", category="restaurant", limit=30)]

    assert {r.place_id: r.source for r in rows} == {"node/1": "openstreetmap", "node/2": "openai"}


def test_the_lane_totals_split_by_lane_and_sum_to_net_new():
    osm = FakeOsm(
        business("Cafe Leona", "node/1"),
        sweep=tag_sweep(("amenity=fast_food", business("Mang Inasal", "node/2"))),
    )
    ai = FakeAiWithTags(business("Grandpa's Inn", "node/3"), selectors=["amenity=fast_food"])
    provider = composite(osm, ai)

    provider.discover(area="Vigan", category="restaurant", limit=30)
    stats = provider.stats()

    assert stats["expansion_net_new"] == 1
    assert stats["name_net_new"] == 1
    assert stats["net_new"] == 2


# --- lane 2 can never reduce the baseline -----------------------------------------


def test_an_expansion_failure_status_leaves_the_baseline_intact():
    osm = FakeOsm(business("Cafe Leona", "node/1"), business("Hidden Garden", "node/2"))
    ai = FakeAiWithTags(status="auth_failed", error="http_401")
    provider = composite(osm, ai)

    result = provider.discover(area="Vigan", category="restaurant", limit=30)

    assert [b.provider_id for b in result] == ["node/1", "node/2"]
    assert provider.stats()["expansion_status"] == "auth_failed"
    assert provider.stats()["execution_status"] == "ok"


def test_an_expansion_exception_leaves_the_baseline_intact():
    osm = FakeOsm(business("Cafe Leona", "node/1"))
    provider = composite(osm, FakeAiWithTags(raises_expand=RuntimeError("boom")))

    result = provider.discover(area="Vigan", category="restaurant", limit=30)

    assert [b.provider_id for b in result] == ["node/1"]
    assert provider.stats()["expansion_status"] == "provider_errored"


def test_an_overpass_failure_during_the_expansion_sweep_leaves_the_baseline_intact():
    # The sweep reaches Overpass and Nominatim, which fail in ways the lane does not model.
    osm = FakeOsm(business("Cafe Leona", "node/1"), sweep_raises=RuntimeError("rate limited"))
    provider = composite(osm, FakeAiWithTags(selectors=["amenity=fast_food"]))

    result = provider.discover(area="Vigan", category="restaurant", limit=30)

    assert [b.provider_id for b in result] == ["node/1"]
    assert provider.stats()["expansion_status"] == "provider_errored"
    assert provider.stats()["execution_status"] == "ok"


def test_an_ai_leg_without_the_capability_is_not_a_failure():
    # An older provider simply does not offer expansion. That is a fact, not an error.
    provider = composite(FakeOsm(business("Cafe Leona", "node/1")), FakeAi())

    provider.discover(area="Vigan", category="restaurant", limit=30)

    assert provider.stats()["expansion_status"] == EXPANSION_UNSUPPORTED
    assert provider.stats()["execution_status"] == "ok"


def test_neither_lane_is_attempted_when_the_baseline_fills_the_limit():
    osm = FakeOsm(business("A", "node/1"), business("B", "node/2"),
                  sweep=tag_sweep(("amenity=fast_food", business("C", "node/3"))))
    ai = FakeAiWithTags(selectors=["amenity=fast_food"])
    provider = composite(osm, ai)

    provider.discover(area="Vigan", category="restaurant", limit=2)

    assert ai.expand_calls == []
    assert osm.selector_calls == []
    assert provider.stats()["expansion_status"] == SKIPPED_BASELINE_FULL


def test_a_raw_selector_category_is_passed_through_as_already_covered():
    # `category_selectors` parses a raw "key=value" category, so the caller's own selector IS what
    # the baseline swept and the model must be told not to repeat it.
    osm = FakeOsm(business("A", "node/1"))
    ai = FakeAiWithTags(selectors=["shop=chemist"])
    provider = composite(osm, ai)

    provider.discover(area="Vigan", category="amenity=veterinary", limit=30)

    assert ai.expand_calls[0][1] == [("amenity", "veterinary")]
    assert provider.stats()["expansion_status"] == "ok"


def test_a_category_the_provider_cannot_parse_still_lets_expansion_run():
    # An unrecognised word makes `category_selectors` raise. The lane has nothing to declare as
    # already-covered, which is not a reason to skip it — and certainly not a reason to fail the run.
    osm = FakeOsm(business("A", "node/1"))
    ai = FakeAiWithTags(selectors=["shop=chemist"])
    provider = composite(osm, ai)

    provider.discover(area="Vigan", category="not_a_known_category", limit=30)

    assert ai.expand_calls[0][1] == []
    assert provider.stats()["expansion_status"] == "ok"
    assert provider.stats()["execution_status"] == "ok"

