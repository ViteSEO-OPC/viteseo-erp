"""Provenance-only AI findings — AI-lane businesses the per-search limit drops, kept ASIDE.

The combined ``openai`` flow (OpenStreetMap baseline + AI lanes) returns at most ``limit`` businesses,
baseline first. An AI-lane finding past that limit used to vanish, even when the CRM already held the
business as a lead the AI lane had genuinely found. These tests pin both halves of the fix:

  * THE RESULT IS UNTOUCHED — same businesses, same order, same limit, same payloads, same stats, same
    ingest call — checked differentially against the original one-line truncation;
  * the dropped AI findings travel beside it as four-field provenance-only records, and only those.

No network: every lane and the CRM client are injected.
"""

from __future__ import annotations

import json

import httpx
import pytest

from scraper import cli, run_metrics
from scraper.ingest import CrmIngestClient
from scraper.models import NormalizedBusiness, ProvenanceOnlyRecord, RawBusiness
from scraper.normalizer import normalize, normalize_provenance
from scraper.partition import DiscoveryTask
from scraper.providers.ai_assisted import LANE_AI, LANE_BASELINE, AiAssistedDiscoveryProvider
from scraper.providers.openstreetmap import SelectorSweep
from tests.test_ai_assisted import FakeAi, FakeAiWithTags, FakeOsm, business


def ids(records) -> list[str]:
    return [r.provider_id for r in records]


class OriginalTruncation(AiAssistedDiscoveryProvider):
    """The composite exactly as it truncated before this change: ``list(merged.values())[:limit]``."""

    def _within_limit(self, merged, limit):
        return list(merged.values())[:limit]


def run_both(make_osm, make_ai, limit: int):
    """The same inputs through the current composite and through the original truncation."""
    new = AiAssistedDiscoveryProvider(osm=make_osm(), ai=make_ai())
    old = OriginalTruncation(osm=make_osm(), ai=make_ai())
    new_result = new.discover(area="Davao", category="restaurant", limit=limit)
    old_result = old.discover(area="Davao", category="restaurant", limit=limit)
    return new, new_result, old, old_result


def payload_bytes(records) -> str:
    return json.dumps([normalize(r, "openai").to_payload() for r in records], sort_keys=True)


# Baseline 3 (node/1..3), tag expansion adds node/4..5 and re-finds node/1, the name lane adds
# node/6..7 and re-finds node/2 — seven distinct businesses against a limit of 4.
def crowded_osm():
    sweep = SelectorSweep(
        businesses=[business("Tag Bakery 4", "node/4"), business("Tag Bakery 5", "way/5"), business("Base 1", "node/1")],
        by_selector={"amenity=fast_food": ["node/4", "way/5", "node/1"]},
    )
    return FakeOsm(business("Base 1", "node/1"), business("Base 2", "node/2"), business("Base 3", "node/3"), sweep=sweep)


def crowded_ai():
    return FakeAiWithTags(
        business("Name Bakery 6", "node/6"), business("Name Bakery 7", "relation/7"), business("Base 2", "node/2"),
        selectors=["amenity=fast_food"],
    )


# ── the combined flow's result is byte-identical ────────────────────────────────────────────────


@pytest.mark.parametrize("limit", [1, 3, 4, 5, 7, 20])
def test_the_result_is_byte_identical_to_the_original_truncation(limit):
    new, new_result, old, old_result = run_both(crowded_osm, crowded_ai, limit)

    assert ids(new_result) == ids(old_result)
    assert payload_bytes(new_result) == payload_bytes(old_result)
    assert new.stats() == old.stats()


def test_the_result_is_byte_identical_when_the_name_lane_raises():
    new, new_result, old, old_result = run_both(
        crowded_osm, lambda: FakeAiWithTags(selectors=["amenity=fast_food"], raises=TimeoutError("read")), 4
    )

    assert payload_bytes(new_result) == payload_bytes(old_result)
    assert new.stats() == old.stats()


def test_the_result_is_byte_identical_when_the_baseline_fills_the_limit():
    new, new_result, old, old_result = run_both(crowded_osm, crowded_ai, 3)

    assert payload_bytes(new_result) == payload_bytes(old_result)
    assert new.stats()["ai_skipped"] == old.stats()["ai_skipped"]


def test_the_existing_limit_and_baseline_first_order_are_kept():
    provider = AiAssistedDiscoveryProvider(osm=crowded_osm(), ai=crowded_ai())

    result = provider.discover(area="Davao", category="restaurant", limit=4)

    assert ids(result) == ["node/1", "node/2", "node/3", "node/4"]


# ── what is set aside ───────────────────────────────────────────────────────────────────────────


def test_only_ai_lane_findings_past_the_limit_are_set_aside():
    provider = AiAssistedDiscoveryProvider(osm=crowded_osm(), ai=crowded_ai())

    provider.discover(area="Davao", category="restaurant", limit=4)
    dropped = provider.provenance_only()

    # Both AI lanes: tag expansion's way/5 and the name lane's node/6 and relation/7.
    assert ids(dropped) == ["way/5", "node/6", "relation/7"]
    assert all(b.discovered_by == LANE_AI for b in dropped)


def test_baseline_records_are_never_set_aside_and_keep_their_lane():
    provider = AiAssistedDiscoveryProvider(osm=crowded_osm(), ai=crowded_ai())

    result = provider.discover(area="Davao", category="restaurant", limit=4)

    assert not any(b.discovered_by == LANE_BASELINE for b in provider.provenance_only())
    # A baseline record the AI lane re-found carries it as ADDITIONAL provenance, as before; a baseline
    # record only the sweep found stays OpenStreetMap-only.
    by_id = {b.provider_id: b for b in result}
    assert by_id["node/1"].also_found_by == [LANE_AI]
    assert by_id["node/2"].also_found_by == [LANE_AI]
    assert by_id["node/3"].also_found_by == []


def test_nothing_is_set_aside_when_everything_fits():
    provider = AiAssistedDiscoveryProvider(osm=crowded_osm(), ai=crowded_ai())

    provider.discover(area="Davao", category="restaurant", limit=20)

    assert provider.provenance_only() == []


def test_nothing_is_set_aside_when_the_baseline_fills_the_limit_and_the_ai_is_not_called():
    ai = crowded_ai()
    provider = AiAssistedDiscoveryProvider(osm=crowded_osm(), ai=ai)

    provider.discover(area="Davao", category="restaurant", limit=3)

    assert ai.called is False
    assert provider.provenance_only() == []


def test_expansion_findings_past_the_limit_are_kept_aside_when_the_name_lane_raises():
    provider = AiAssistedDiscoveryProvider(
        osm=crowded_osm(), ai=FakeAiWithTags(selectors=["amenity=fast_food"], raises=TimeoutError("read"))
    )

    result = provider.discover(area="Davao", category="restaurant", limit=4)

    assert ids(result) == ["node/1", "node/2", "node/3", "node/4"]
    assert ids(provider.provenance_only()) == ["way/5"]


def test_a_search_never_reports_the_previous_searchs_findings():
    provider = AiAssistedDiscoveryProvider(osm=crowded_osm(), ai=crowded_ai())
    provider.discover(area="Davao", category="restaurant", limit=4)

    provider.discover(area="Davao", category="restaurant", limit=20)

    assert provider.provenance_only() == []


def test_provenance_only_is_a_copy():
    provider = AiAssistedDiscoveryProvider(osm=crowded_osm(), ai=crowded_ai())
    provider.discover(area="Davao", category="restaurant", limit=4)

    provider.provenance_only().clear()

    assert len(provider.provenance_only()) == 3


# ── the provenance-only record ──────────────────────────────────────────────────────────────────


def test_a_provenance_record_is_exactly_four_fields():
    raw = RawBusiness(provider_id="node/6", name="Name Bakery", latitude=7.07, longitude=125.61,
                      phone="+63 82 000", email="x@y.test", website="https://x.test", category="restaurant")

    record = normalize_provenance(raw, LANE_AI)

    assert record.to_payload() == {"place_id": "node/6", "lane": "openai", "latitude": 7.07, "longitude": 125.61}


@pytest.mark.parametrize("provider_id", ["places/ChIJabc", "node/0", "node/", "osm:node/1", "", None])
def test_only_an_osm_identity_can_become_a_provenance_record(provider_id):
    raw = RawBusiness(provider_id=provider_id or "", name="X", latitude=7.0, longitude=125.0)

    assert normalize_provenance(raw, LANE_AI) is None


def test_a_record_without_a_position_cannot_become_one():
    assert normalize_provenance(RawBusiness(provider_id="node/6", name="X", latitude=None, longitude=125.0), LANE_AI) is None
    assert normalize_provenance(RawBusiness(provider_id="node/6", name="X", latitude=7.0, longitude=None), LANE_AI) is None


# ── the ingest request ──────────────────────────────────────────────────────────────────────────


def _client(sent: list):
    client = CrmIngestClient(base_url="http://crm.test/api/v1/crm", token="t", user_agent="ua")

    def handler(request: httpx.Request) -> httpx.Response:
        sent.append(json.loads(request.content))
        return httpx.Response(200, json={"data": {"summary": {}, "importedCount": 0}})

    client._client = httpx.Client(transport=httpx.MockTransport(handler))
    return client


def test_an_ordinary_request_is_exactly_the_request_it_was():
    sent: list = []
    businesses = [NormalizedBusiness(business_name="A", source="openai", place_id="node/1")]

    _client(sent).ingest(businesses, dry_run=False)
    _client(sent).ingest(businesses, dry_run=False, provenance_only=[])

    expected = {"dry_run": False, "businesses": [businesses[0].to_payload()]}
    assert sent == [expected, expected]


def test_findings_travel_beside_the_unchanged_batch():
    sent: list = []
    businesses = [NormalizedBusiness(business_name="A", source="openai", place_id="node/1")]
    findings = [ProvenanceOnlyRecord(place_id="node/6", lane="openai", latitude=7.0, longitude=125.0)]

    _client(sent).ingest(businesses, dry_run=False, provenance_only=findings)

    assert sent[0]["businesses"] == [businesses[0].to_payload()]
    assert sent[0]["provenance_only"] == [{"place_id": "node/6", "lane": "openai", "latitude": 7.0, "longitude": 125.0}]


# ── the CLI: one discovery, end to end ──────────────────────────────────────────────────────────


class Recorder:
    """A CRM client that records the exact call it received."""

    calls: list[dict] = []
    summary: dict = {}

    def __init__(self, *, base_url: str, token: str, user_agent: str) -> None:
        pass

    def ingest(self, businesses, **kwargs) -> dict:
        Recorder.calls.append({
            "businesses": [b.to_payload() for b in businesses],
            "kwargs": {k: ([p.to_payload() for p in v] if k == "provenance_only" else v) for k, v in kwargs.items()},
        })
        return {"summary": dict(Recorder.summary), "importedCount": 0}

    def close(self) -> None:
        pass


@pytest.fixture
def cli_env(monkeypatch, tmp_path):
    Recorder.calls = []
    Recorder.summary = {"total": 4, "valid": 0, "duplicates": 4, "rejected": 0}
    metrics = tmp_path / "metrics.jsonl"
    monkeypatch.setenv(run_metrics.METRICS_FILE_ENV, str(metrics))
    monkeypatch.setenv("CRM_TOKEN", "test-token-not-real")
    monkeypatch.setattr(cli, "CrmIngestClient", Recorder)
    return metrics


def _discover(monkeypatch, provider_factory, *extra) -> int:
    monkeypatch.setattr(cli, "get_provider", lambda config: provider_factory())
    return cli.main(["discover", "--area", "Davao", "--category", "restaurant", "--limit", "4", "--commit", *extra])


def test_the_cli_sends_the_same_batch_plus_the_dropped_ai_findings(monkeypatch, cli_env):
    assert _discover(monkeypatch, lambda: AiAssistedDiscoveryProvider(osm=crowded_osm(), ai=crowded_ai())) == 0
    assert _discover(monkeypatch, lambda: OriginalTruncation(osm=crowded_osm(), ai=crowded_ai())) == 0

    new_call, old_call = Recorder.calls
    # The batch is byte-for-byte the batch the original flow sent.
    assert json.dumps(new_call["businesses"], sort_keys=True) == json.dumps(old_call["businesses"], sort_keys=True)
    assert new_call["kwargs"]["dry_run"] is False
    assert [p["place_id"] for p in new_call["kwargs"]["provenance_only"]] == ["way/5", "node/6", "relation/7"]
    assert all(p["lane"] == "openai" for p in new_call["kwargs"]["provenance_only"])


def test_the_cli_makes_the_ordinary_call_when_nothing_was_dropped(monkeypatch, cli_env):
    _discover(monkeypatch, lambda: AiAssistedDiscoveryProvider(osm=crowded_osm(), ai=crowded_ai()), "--limit", "20")

    assert Recorder.calls[0]["kwargs"] == {"dry_run": False}


class PlainOsm(FakeOsm):
    """A provider without the capability — the plain OpenStreetMap run."""

    name = "openstreetmap"


def test_a_provider_without_the_capability_is_exactly_as_before(monkeypatch, cli_env):
    _discover(monkeypatch, lambda: PlainOsm(business("Base 1", "node/1")))

    assert Recorder.calls[0]["kwargs"] == {"dry_run": False}
    record = run_metrics.load(cli_env)[-1]
    assert not any(k.startswith("provenance_only") for k in record)


def test_a_dry_run_still_says_dry_run(monkeypatch, cli_env):
    monkeypatch.setattr(cli, "get_provider", lambda config: AiAssistedDiscoveryProvider(osm=crowded_osm(), ai=crowded_ai()))
    cli.main(["discover", "--area", "Davao", "--category", "restaurant", "--limit", "4"])

    assert Recorder.calls[0]["kwargs"]["dry_run"] is True


def test_metrics_record_what_was_sent_and_what_the_crm_recorded(monkeypatch, cli_env):
    Recorder.summary = {**Recorder.summary, "provenanceOnly": {"received": 3, "invalid": 0, "outsidePh": 1, "recorded": 1}}

    _discover(monkeypatch, lambda: AiAssistedDiscoveryProvider(osm=crowded_osm(), ai=crowded_ai()))

    record = run_metrics.load(cli_env)[-1]
    assert record["provenance_only_sent"] == 3
    assert record["provenance_only_recorded"] == 1
    assert record["provenance_only_outside_ph"] == 1
    # The result's own numbers are not touched by what was set aside.
    assert record["discovered"] == 4
    assert record["duplicates"] == 4


def test_metrics_do_not_guess_when_the_crm_did_not_answer(monkeypatch, cli_env):
    _discover(monkeypatch, lambda: AiAssistedDiscoveryProvider(osm=crowded_osm(), ai=crowded_ai()))

    record = run_metrics.load(cli_env)[-1]
    assert record["provenance_only_sent"] == 3
    assert record["provenance_only_recorded"] is None


# ── several cells in one process ────────────────────────────────────────────────────────────────


class CellProvider:
    """Each call returns its cell's result and its own dropped findings, like the composite."""

    def __init__(self, cells: dict[str, tuple[list[RawBusiness], list[RawBusiness]]]) -> None:
        self._cells = cells
        self._last: list[RawBusiness] = []

    def discover(self, *, area, category, limit, bbox=None):
        found, dropped = self._cells[area]
        self._last = list(dropped)
        return list(found)

    def provenance_only(self):
        return list(self._last)


def test_each_cells_dropped_findings_are_collected():
    ai = lambda pid: RawBusiness(provider_id=pid, name=pid, latitude=7.0, longitude=125.0, discovered_by=LANE_AI)  # noqa: E731
    provider = CellProvider({
        "A": ([business("Kept A", "node/1")], [ai("node/10")]),
        "B": ([business("Kept B", "node/2")], [ai("node/11"), ai("node/10")]),
    })
    sink: list = []

    result = cli._run_tasks(provider, [DiscoveryTask(area="A", category="restaurant", limit=20),
                                       DiscoveryTask(area="B", category="restaurant", limit=20)], 20, sink)

    assert ids(result) == ["node/1", "node/2"]
    assert ids(sink) == ["node/10", "node/11", "node/10"]  # deduplicated later, on normalisation


def test_the_single_cell_path_still_returns_the_providers_result_untouched():
    provider = CellProvider({"A": ([business("Kept", "node/1")], [])})

    result = cli._run_tasks(provider, [DiscoveryTask(area="A", category="restaurant", limit=20)], 20)

    assert ids(result) == ["node/1"]
