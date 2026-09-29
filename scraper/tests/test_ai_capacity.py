"""AI-assisted capacity mode — more AI capacity per search, the OpenStreetMap sweep untouched.

  * OFF unless ``ai_total_limit`` is configured: every provider is built and behaves exactly as before.
  * ON (combined ``openai`` provider only): the sweep stays capped at ``limit``; the AI lanes may fill
    the result up to ``ai_total_limit``; their candidates are geocoded only when returned (R1); and
    ``skip_ai_lanes`` runs the sweep alone for an area and niche the run already covered (R3).

No network: every lane is injected, and the one real-client test uses an httpx mock transport.
"""

from __future__ import annotations

import httpx
import pytest

from scraper import cli, run_metrics
from scraper.call_counts import CallCounter
from scraper.config import Config
from scraper.models import RawBusiness
from scraper.providers import build_openstreetmap, get_provider
from scraper.providers.ai_assisted import (
    LANE_AI,
    LANE_BASELINE,
    SKIPPED_ALREADY_COVERED,
    AiAssistedDiscoveryProvider,
)
from scraper.providers.openstreetmap import OpenStreetMapProvider, SelectorSweep
from tests.test_ai_assisted import FakeAi, FakeAiWithTags, FakeOsm, business


def ids(records) -> list[str]:
    return [r.provider_id for r in records]


def config(**overrides) -> Config:
    base = dict(
        discovery_provider="openai", crm_base_url="http://crm.test/api/v1/crm", crm_token="t",
        overpass_url="https://overpass.test/api/interpreter", nominatim_url="https://nominatim.test",
        user_agent="ua", request_timeout=5.0,
    )
    return Config(**{**base, **overrides})


class GeocodingOsm(FakeOsm):
    """The sweep's client: records every reverse geocode the composite asks it for."""

    def __init__(self, *found, **kw) -> None:
        super().__init__(*found, **kw)
        self.geocoded: list[str] = []

    def _fill_admin_area(self, business: RawBusiness) -> None:
        self.geocoded.append(business.provider_id)
        business.region, business.province, business.city = "Region XI", "Davao del Sur", "Davao City"


class LaneOsm(FakeOsm):
    """The AI lanes' own client (built without per-business geocoding)."""

    def _fill_admin_area(self, business: RawBusiness) -> None:  # pragma: no cover - must never be used
        raise AssertionError("the AI lanes' client must never geocode")


class RecordingAi(FakeAi):
    def __init__(self, *found, **kw) -> None:
        super().__init__(*found, **kw)
        self.limits: list[int] = []

    def discover(self, *, area, category, limit, **kw):
        self.limits.append(limit)
        return super().discover(area=area, category=category, limit=limit)


class FakeCounter:
    def __init__(self) -> None:
        self.counts = {"overpass": 0, "nominatim_search": 0, "nominatim_reverse": 0, "ai": 0, "other": 0}

    def snapshot(self) -> dict:
        return dict(self.counts)


def capacity(baseline, ai, *, lane=None, total=100, skip=False, counter=None):
    return AiAssistedDiscoveryProvider(
        osm=baseline, ai=ai, lane_osm=lane or LaneOsm(), total_limit=total, skip_ai_lanes=skip, call_counter=counter,
    )


def many(prefix: str, start: int, n: int) -> list[RawBusiness]:
    return [business(f"{prefix} {i}", f"node/{i}") for i in range(start, start + n)]


# ── off unless configured ───────────────────────────────────────────────────────────────────────


def test_unconfigured_the_openai_provider_is_built_exactly_as_before():
    provider = get_provider(config())

    assert isinstance(provider, AiAssistedDiscoveryProvider)
    assert provider._lane_osm is provider._osm, "one shared OSM client, as before"
    assert provider._defer_lane_geocoding is False
    assert provider._total_limit is None and provider._counter is None and provider._skip_ai_lanes is False
    assert provider._osm.reverse_geocode is True
    provider.close()


def test_unconfigured_a_search_reports_exactly_the_stats_it_always_did():
    provider = AiAssistedDiscoveryProvider(osm=FakeOsm(*many("Base", 1, 3)), ai=FakeAi(*many("Ai", 50, 2)))

    provider.discover(area="Davao", category="restaurant", limit=20)

    assert not {"ai_total_limit", "ai_geocoded", "calls"} & set(provider.stats())


@pytest.mark.parametrize("name", ["openstreetmap", "osm"])
def test_the_plain_openstreetmap_provider_ignores_the_setting(name):
    provider = get_provider(config(discovery_provider=name, ai_total_limit=100, skip_ai_lanes=True))

    assert type(provider) is OpenStreetMapProvider
    assert provider.reverse_geocode is True
    assert provider._client.event_hooks["request"] == []
    provider.close()


def test_the_google_places_provider_ignores_the_setting():
    plain = get_provider(config(discovery_provider="google_places"))
    configured = get_provider(config(discovery_provider="google_places", ai_total_limit=100, skip_ai_lanes=True))

    assert type(configured) is type(plain)
    assert vars(configured).keys() == vars(plain).keys()
    for p in (plain, configured):
        p.close()


def test_build_openstreetmap_is_unchanged_by_default():
    assert build_openstreetmap(config()).reverse_geocode is True
    assert build_openstreetmap(config(), reverse_geocode=False).reverse_geocode is False


# ── capacity mode: construction ─────────────────────────────────────────────────────────────────


def test_configured_the_lanes_get_their_own_non_geocoding_client_and_everything_is_counted():
    provider = get_provider(config(ai_total_limit=100))

    assert isinstance(provider, AiAssistedDiscoveryProvider)
    assert provider._lane_osm is not provider._osm
    assert provider._osm.reverse_geocode is True, "the sweep geocodes exactly as before"
    assert provider._lane_osm.reverse_geocode is False
    assert provider._total_limit == 100
    assert provider._ai._osm is provider._lane_osm, "the name lane resolves through the lanes' client"
    for client in (provider._osm._client, provider._lane_osm._client, provider._ai._client):
        assert len(client.event_hooks["request"]) == 1
    provider.close()
    assert provider._osm._client.is_closed and provider._lane_osm._client.is_closed


# ── capacity mode: behaviour ────────────────────────────────────────────────────────────────────


def test_the_sweep_stays_capped_at_the_limit_and_the_ai_lanes_fill_to_the_total():
    baseline = GeocodingOsm(*many("Base", 1, 20))
    ai = RecordingAi(*many("Ai", 100, 90))

    result = capacity(baseline, ai).discover(area="Davao", category="restaurant", limit=20)

    assert baseline.calls == [{"area": "Davao", "category": "restaurant", "limit": 20}]
    assert ai.limits == [100]
    assert len(result) == 100
    assert ids(result)[:20] == [f"node/{i}" for i in range(1, 21)], "sweep first, exactly as returned"
    assert all(b.discovered_by == LANE_BASELINE for b in result[:20])
    assert all(b.discovered_by == LANE_AI for b in result[20:])


def test_a_full_sweep_no_longer_crowds_out_the_ai_lanes():
    ai = RecordingAi(*many("Ai", 100, 5))

    result = capacity(GeocodingOsm(*many("Base", 1, 20)), ai).discover(area="Davao", category="restaurant", limit=20)

    assert ai.limits == [100]
    assert len(result) == 25


def test_the_expansion_lane_sweeps_through_the_lanes_client_up_to_the_total():
    lane = LaneOsm(sweep=SelectorSweep(businesses=many("Tag", 200, 3), by_selector={"amenity=fast_food": ["node/200"]}))
    provider = capacity(GeocodingOsm(*many("Base", 1, 2)), FakeAiWithTags(selectors=["amenity=fast_food"]), lane=lane)

    result = provider.discover(area="Davao", category="restaurant", limit=20)

    assert lane.selector_calls, "the expansion sweep used the lanes' client"
    assert ids(result) == ["node/1", "node/2", "node/200", "node/201", "node/202"]


def test_r1_only_returned_ai_candidates_without_an_admin_area_are_geocoded():
    baseline = GeocodingOsm(*many("Base", 1, 18))
    has_area = business("Ai with area", "node/300")
    has_area.region, has_area.province, has_area.city = "R", "P", "C"
    ai = FakeAi(
        business("Dup of base", "node/1"),     # merges into the sweep's record — never geocoded
        has_area,                               # already has its area — never geocoded
        *many("Ai", 100, 5),                    # only node/100 fits: 18 + node/300 + node/100 = 20
    )

    result = capacity(baseline, ai, total=20).discover(area="Davao", category="restaurant", limit=18)

    kept_ai = [b.provider_id for b in result if b.discovered_by == LANE_AI]
    assert kept_ai == ["node/300", "node/100"]
    assert baseline.geocoded == ["node/100"], "one Nominatim call: the one returned AI candidate lacking its area"


def test_r1_candidates_past_the_total_are_never_geocoded_and_stay_provenance_only():
    baseline = GeocodingOsm(*many("Base", 1, 20))
    provider = capacity(baseline, FakeAi(*many("Ai", 100, 90)), total=100)

    provider.discover(area="Davao", category="restaurant", limit=20)

    assert len(baseline.geocoded) == 80
    assert ids(provider.provenance_only()) == [f"node/{i}" for i in range(180, 190)]
    assert not set(baseline.geocoded) & set(ids(provider.provenance_only()))


def test_r1_a_returned_candidate_carries_the_same_area_the_sweep_would_give_it():
    baseline = GeocodingOsm()
    result = capacity(baseline, FakeAi(business("Ai", "node/100"))).discover(area="Davao", category="restaurant", limit=20)

    assert (result[0].region, result[0].province, result[0].city) == ("Region XI", "Davao del Sur", "Davao City")


def test_r3_skip_runs_the_sweep_alone():
    baseline = GeocodingOsm(*many("Base", 1, 7))
    ai = FakeAiWithTags(*many("Ai", 100, 5), selectors=["amenity=fast_food"])
    provider = capacity(baseline, ai, skip=True)

    result = provider.discover(area="Davao", category="restaurant", limit=20)

    assert ids(result) == [f"node/{i}" for i in range(1, 8)]
    assert ai.called is False and ai.expand_calls == []
    assert provider.stats()["ai_skipped"] == SKIPPED_ALREADY_COVERED
    assert provider.stats()["ai_execution_status"] == SKIPPED_ALREADY_COVERED
    assert provider.stats()["baseline_discovered"] == 7
    assert provider.provenance_only() == []
    assert baseline.geocoded == []


def test_capacity_stats_report_the_total_the_geocodes_and_this_searchs_calls():
    counter = FakeCounter()
    counter.counts["overpass"] = 4  # a previous search in the same process

    class CountingAi(FakeAi):
        def discover(self, **kw):
            counter.counts["ai"] += 1
            counter.counts["overpass"] += 1
            return super().discover(**kw)

    provider = capacity(GeocodingOsm(*many("Base", 1, 2)), CountingAi(*many("Ai", 100, 3)), counter=counter)
    provider.discover(area="Davao", category="restaurant", limit=20)
    stats = provider.stats()

    assert stats["ai_total_limit"] == 100
    assert stats["ai_geocoded"] == 3
    assert stats["calls"] == {"overpass": 1, "nominatim_search": 0, "nominatim_reverse": 0, "ai": 1, "other": 0}


# ── the call counter ────────────────────────────────────────────────────────────────────────────


def test_the_counter_classifies_every_real_request_by_service():
    counter = CallCounter(overpass_url="https://overpass.test/api/interpreter", nominatim_url="https://nominatim.test")
    transport = httpx.MockTransport(lambda request: httpx.Response(200, json={}))
    osm_client, ai_client = httpx.Client(transport=transport), httpx.Client(transport=transport)
    counter.attach(osm_client)
    counter.attach(ai_client, as_ai=True)

    osm_client.post("https://overpass.test/api/interpreter")
    osm_client.post("https://overpass.test/api/interpreter")
    osm_client.get("https://nominatim.test/search", params={"q": "Davao"})
    osm_client.get("https://nominatim.test/reverse", params={"lat": 7, "lon": 125})
    osm_client.get("https://elsewhere.test/x")
    ai_client.post("http://crm.test/api/v1/crm/discovery/proposals")

    assert counter.snapshot() == {"overpass": 2, "nominatim_search": 1, "nominatim_reverse": 1, "ai": 1, "other": 1}


# ── the CLI ─────────────────────────────────────────────────────────────────────────────────────


@pytest.fixture
def captured(monkeypatch, tmp_path):
    seen: list[Config] = []
    monkeypatch.setenv(run_metrics.METRICS_FILE_ENV, str(tmp_path / "m.jsonl"))
    monkeypatch.setattr(cli, "get_provider", lambda c: (seen.append(c), FakeOsm())[1])
    FakeOsm.name = "openstreetmap"
    yield seen
    del FakeOsm.name


def test_the_cli_without_the_flags_passes_the_config_it_always_passed(captured):
    cli.main(["discover", "--area", "Davao", "--category", "restaurant", "--print-only"])

    assert captured[0].ai_total_limit is None and captured[0].skip_ai_lanes is False


def test_the_cli_carries_the_flags_into_the_config(captured):
    cli.main(["discover", "--area", "Davao", "--category", "restaurant", "--print-only",
              "--ai-total-limit", "100", "--skip-ai-lanes"])

    assert captured[0].ai_total_limit == 100 and captured[0].skip_ai_lanes is True


def test_metrics_carry_the_capacity_numbers_only_when_the_search_reported_them():
    plain = run_metrics.build_record(provider="openai", area="A", category="c", limit=20, commit=True,
                                     duration_ms=1, discovered=3, with_website=0,
                                     provider_stats={"baseline_discovered": 3})
    capacity_record = run_metrics.build_record(provider="openai", area="A", category="c", limit=20, commit=True,
                                               duration_ms=1, discovered=3, with_website=0,
                                               provider_stats={"baseline_discovered": 3, "ai_total_limit": 100,
                                                               "ai_geocoded": 2, "calls": {"overpass": 3}})

    assert not {"ai_total_limit", "ai_geocoded", "calls"} & set(plain)
    assert capacity_record["ai_total_limit"] == 100
    assert capacity_record["ai_geocoded"] == 2
    assert capacity_record["calls"] == {"overpass": 3}
