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
    LANE_AI,
    LANE_BASELINE,
    SKIPPED_BASELINE_FULL,
    AiAssistedDiscoveryProvider,
)


def business(name: str, provider_id: str) -> RawBusiness:
    return RawBusiness(provider_id=provider_id, name=name, latitude=14.5, longitude=121.0)


class FakeOsm:
    """Stands in for OpenStreetMapProvider.discover()."""

    def __init__(self, *found: RawBusiness) -> None:
        self._found = list(found)
        self.calls: list[dict] = []

    def discover(self, *, area: str, category: str, limit: int) -> list[RawBusiness]:
        self.calls.append({"area": area, "category": category, "limit": limit})
        return [b.model_copy(deep=True) for b in self._found][:limit]


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
