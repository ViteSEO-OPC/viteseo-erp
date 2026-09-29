"""Volume mode — one raw-capped sweep per cell, new businesses first, geocoding only for those.

  * OFF unless ``volume`` is set: every provider is built exactly as before.
  * ON (combined ``openai`` only): the sweep reads up to ``raw_cap`` raw elements; the CRM says which
    keys it holds; up to ``task_cap`` NEW businesses are returned and geocoded (R2), likely-no-website
    first; known businesses are still returned, ungeocoded, so the ingest sees what the sweep saw.
  * The name lane never runs; the tag-expansion lane feeds the sweep and keeps `openai` provenance.
  * Truncation is judged on RAW elements, so unnamed elements can no longer fake "exhausted".

No network: the OSM client is faked at ``sweep_cell``; the real query builder is checked via httpx mock.
"""

from __future__ import annotations

import json

import httpx
import pytest

from scraper import cli, run_metrics
from scraper.config import Config
from scraper.known_places import KnownPlacesClient
from scraper.providers import get_provider
from scraper.providers.ai_assisted import LANE_AI, LANE_BASELINE, AiAssistedDiscoveryProvider
from scraper.providers.openstreetmap import CellSweep, OpenStreetMapProvider
from scraper.providers.volume import NAME_LANE_OFF, VolumeDiscoveryProvider


def config(**overrides) -> Config:
    base = dict(
        discovery_provider="openai", crm_base_url="http://crm.test/api/v1/crm", crm_token="t",
        overpass_url="https://overpass.test/api/interpreter", nominatim_url="https://nominatim.test",
        user_agent="ua", request_timeout=5.0,
    )
    return Config(**{**base, **overrides})


def element(i: int, *, name: str | None = "Clinic", tags: dict | None = None, kind: str = "node") -> dict:
    t = {"amenity": "dentist", **(tags or {})}
    if name is not None:
        t["name"] = f"{name} {i}"
    return {"type": kind, "id": i, "lat": 14.6, "lon": 121.0, "tags": t}


class FakeSweepOsm:
    def __init__(self, elements: list[dict]) -> None:
        self.elements = elements
        self.swept: list[tuple] = []
        self.geocoded: list[str] = []
        self.closed = False

    def sweep_cell(self, selectors, bbox, raw_cap):
        self.swept.append((list(selectors), bbox, raw_cap))
        return CellSweep(elements=self.elements[:raw_cap], raw_cap=raw_cap)

    def geocode_area(self, area):
        return (14.0, 120.0, 15.0, 121.0)

    def _fill_admin_area(self, business):
        self.geocoded.append(business.provider_id)
        business.region, business.province, business.city = "NCR", "Metro Manila", "Quezon City"

    def close(self):
        self.closed = True


class FakeAi:
    """Tag expansion only. `discover` (the name lane) must never be called."""

    def __init__(self, tags: list[str] | None = None, status: str = "ok", raises: bool = False) -> None:
        self.tags = tags or []
        self.status = status
        self.raises = raises
        self.closed = False

    def discover(self, **kw):  # pragma: no cover - asserted never to run
        raise AssertionError("the name lane must not run in volume mode")

    def expand_tags(self, category, known):
        if self.raises:
            raise RuntimeError("boom")
        return self.tags, self.status, None, {}

    def close(self):
        self.closed = True


def volume(elements, *, known=frozenset(), ai=None, raw_cap=400, task_cap=100, lookup=None):
    osm = FakeSweepOsm(elements)
    provider = VolumeDiscoveryProvider(
        osm=osm, ai=ai or FakeAi(), known=lookup or (lambda ids: {i for i in ids if i in known}),
        raw_cap=raw_cap, task_cap=task_cap,
    )
    return provider, osm


BOX = (14.6, 121.0, 14.9, 121.2)


def run(provider, **kw):
    return provider.discover(area="Quezon City", category="dentist", limit=20, bbox=BOX, **kw)


# -- reaching past the page ---------------------------------------------------------------------


def test_returns_far_more_than_the_default_page_when_the_cell_holds_them():
    provider, osm = volume([element(i) for i in range(1, 175)])
    result = run(provider)
    assert len(result) == 100  # task cap, not the 20-row page
    assert osm.swept[0][2] == 400
    stats = provider.stats()
    assert stats["volume_new_candidates"] == 174
    assert stats["volume_new_returned"] == 100
    assert stats["volume_new_capped"] is True
    assert stats["volume_raw_truncated"] is False


def test_known_businesses_are_returned_ungeocoded_and_do_not_use_the_cap():
    elements = [element(i) for i in range(1, 31)]
    provider, osm = volume(elements, known={f"node/{i}" for i in range(1, 21)}, task_cap=5)
    result = run(provider)
    ids = [b.provider_id for b in result]
    # All 20 known still go to the ingest (it dedups them, as it always has) ...
    assert all(f"node/{i}" in ids for i in range(1, 21))
    # ... but only the 5 NEW ones spend a geocode and a slot.
    assert len(result) == 25
    assert sorted(osm.geocoded) == sorted(i for i in ids if i not in {f"node/{n}" for n in range(1, 21)})
    assert provider.stats()["volume_known"] == 20
    assert provider.stats()["volume_geocoded"] == 5


def test_geocoding_happens_only_for_new_rows_missing_an_admin_area():
    with_addr = element(2, tags={"addr:region": "NCR", "addr:province": "MM", "addr:city": "QC"})
    provider, osm = volume([element(1), with_addr], known={"node/9"})
    run(provider)
    assert osm.geocoded == ["node/1"]


# -- the exhausted bug, fixed here only ------------------------------------------------------------


def test_unnamed_elements_no_longer_fake_an_exhausted_cell():
    # 400 raw elements, a third unnamed: the default sweep would see < page and call it exhausted.
    elements = [element(i, name=None if i % 3 == 0 else "Clinic") for i in range(1, 401)]
    provider, _ = volume(elements)
    run(provider)
    stats = provider.stats()
    assert stats["volume_raw_elements"] == 400
    assert stats["volume_named"] < 400
    assert stats["volume_raw_truncated"] is True


def test_a_fully_read_cell_is_not_truncated_even_when_every_business_is_known():
    elements = [element(i) for i in range(1, 51)]
    provider, osm = volume(elements, known={f"node/{i}" for i in range(1, 51)})
    result = run(provider)
    stats = provider.stats()
    assert stats["volume_raw_truncated"] is False
    assert stats["volume_new_capped"] is False
    assert stats["volume_new_returned"] == 0
    assert len(result) == 50 and osm.geocoded == []


# -- ordering toward stronger existing signals, never filtering -------------------------------------


def test_likely_no_website_businesses_are_preferred_under_the_cap_but_none_is_excluded():
    elements = [
        element(1, tags={"website": "https://a.example"}),
        element(2, tags={"brand:wikidata": "Q1"}),
        element(3),
        element(4, tags={"contact:website": "https://d.example"}),
        element(5),
    ]
    provider, _ = volume(elements, task_cap=2)
    assert [b.provider_id for b in run(provider)] == ["node/3", "node/5"]
    assert provider.stats()["volume_likely_no_website"] == 2

    provider, _ = volume(elements, task_cap=100)
    # With room, every business is returned: the order changed, the set did not.
    assert sorted(b.provider_id for b in run(provider)) == [f"node/{i}" for i in range(1, 6)]


# -- lanes, provenance and trust -------------------------------------------------------------------


def test_the_name_lane_never_runs_and_says_so():
    provider, _ = volume([element(1)])
    run(provider)
    stats = provider.stats()
    assert stats["ai_execution_status"] == NAME_LANE_OFF
    assert stats["name_net_new"] == 0


def test_expanded_tags_join_the_same_sweep_and_keep_ai_provenance():
    elements = [element(1), {"type": "node", "id": 2, "lat": 14.6, "lon": 121.0,
                             "tags": {"healthcare": "dentist", "name": "Tooth Co"}}]
    provider, osm = volume(elements, ai=FakeAi(tags=["healthcare=dentist"]))
    result = {b.provider_id: b for b in run(provider)}
    assert ("healthcare", "dentist") in osm.swept[0][0]
    assert result["node/1"].discovered_by == LANE_BASELINE
    assert result["node/2"].discovered_by == LANE_AI
    stats = provider.stats()
    assert stats["expansion_selectors"] == ["healthcare=dentist"]
    assert stats["expansion_net_new"] == 1
    assert stats["baseline_discovered"] == 1


def test_an_invalid_expanded_tag_is_refused_before_it_can_become_a_query():
    provider, osm = volume([element(1)], ai=FakeAi(tags=["highway=residential"]))
    run(provider)
    assert osm.swept[0][0] == [("amenity", "dentist")]
    assert provider.stats()["expansion_rejected"] == 1


@pytest.mark.parametrize("ai", [FakeAi(status="unreachable"), FakeAi(raises=True)])
def test_expansion_failure_never_costs_the_base_sweep(ai):
    provider, _ = volume([element(i) for i in range(1, 6)], ai=ai)
    assert len(run(provider)) == 5
    assert provider.stats()["expansion_status"] != "ok"


def test_a_failed_known_lookup_treats_every_row_as_possibly_new():
    provider, osm = volume([element(i) for i in range(1, 11)], lookup=lambda ids: None, task_cap=4)
    result = run(provider)
    stats = provider.stats()
    assert stats["volume_known_lookup"] == "failed"
    assert len(result) == 4 and stats["volume_new_capped"] is True  # the rest waits for a child cell
    assert len(osm.geocoded) == 4


def test_unnamed_and_keyless_elements_never_become_businesses():
    elements = [element(1, name=None), {"type": "", "id": "", "tags": {"name": "X", "amenity": "dentist"}}, element(3)]
    provider, _ = volume(elements)
    assert [b.provider_id for b in run(provider)] == ["node/3"]


def test_an_unmapped_niche_fails_loudly_like_the_default_sweep():
    provider, _ = volume([element(1)])
    with pytest.raises(ValueError):
        provider.discover(area="X", category="airport", limit=20, bbox=BOX)


def test_close_releases_every_client():
    closed = []
    ai = FakeAi()
    provider = VolumeDiscoveryProvider(osm=FakeSweepOsm([]), ai=ai, known=lambda ids: set(), raw_cap=10,
                                       task_cap=5, closers=(lambda: closed.append(True),))
    provider.close()
    assert ai.closed and closed == [True]


# -- construction: off by default, openai only -----------------------------------------------------


def test_off_by_default_builds_the_existing_composite():
    assert isinstance(get_provider(config()), AiAssistedDiscoveryProvider)


def test_volume_builds_the_volume_provider_for_openai_only():
    built = get_provider(config(volume=True))
    try:
        assert isinstance(built, VolumeDiscoveryProvider)
    finally:
        built.close()
    osm = get_provider(config(discovery_provider="openstreetmap", volume=True))
    assert isinstance(osm, OpenStreetMapProvider)


def test_the_cli_carries_volume_flags_onto_the_config(monkeypatch):
    seen = {}

    def fake_get_provider(cfg):
        seen["cfg"] = cfg
        raise RuntimeError("stop here")

    monkeypatch.setattr(cli, "get_provider", fake_get_provider)
    monkeypatch.setattr(cli, "resolve_provider_choice", lambda c, o: type("C", (), {"provider": "openai", "source": "cli", "reason": None})())
    monkeypatch.setattr(cli, "config_for", lambda c, choice: c)
    with pytest.raises(RuntimeError):
        cli.main(["discover", "--area", "QC", "--category", "dentist", "--provider", "openai",
                  "--volume", "--raw-cap", "300", "--task-new-cap", "60", "--bbox", "14.6,121.0,14.9,121.2"])
    cfg = seen["cfg"]
    assert cfg.volume is True and cfg.volume_raw_cap == 300 and cfg.volume_task_cap == 60


def test_without_the_flag_the_cli_config_is_unchanged(monkeypatch):
    seen = {}
    monkeypatch.setattr(cli, "get_provider", lambda cfg: seen.setdefault("cfg", cfg) and (_ for _ in ()).throw(RuntimeError()))
    monkeypatch.setattr(cli, "resolve_provider_choice", lambda c, o: type("C", (), {"provider": "openai", "source": "cli", "reason": None})())
    monkeypatch.setattr(cli, "config_for", lambda c, choice: c)
    with pytest.raises(RuntimeError):
        cli.main(["discover", "--area", "QC", "--category", "dentist", "--provider", "openai"])
    assert seen["cfg"].volume is False


# -- the real query and the real CRM client ---------------------------------------------------------


def test_sweep_cell_sends_one_raw_capped_query_with_a_longer_client_timeout():
    sent = {}

    def handler(request: httpx.Request) -> httpx.Response:
        sent["body"] = request.content.decode()
        sent["timeout"] = request.extensions.get("timeout")
        return httpx.Response(200, json={"elements": [element(1), element(2, name=None)]})

    p = OpenStreetMapProvider(overpass_url="https://overpass.test/api/interpreter", nominatim_url="https://n.test",
                              user_agent="ua", reverse_geocode=False)
    p._client = httpx.Client(transport=httpx.MockTransport(handler))
    sweep = p.sweep_cell([("amenity", "dentist"), ("healthcare", "dentist")], BOX, 400)
    assert "out center 400;" in sent["body"]
    assert '[timeout:60]' in sent["body"]
    assert 'node["healthcare"="dentist"]' in sent["body"]
    assert sent["timeout"]["read"] == 75.0
    assert len(sweep.elements) == 2 and sweep.raw_truncated is False


def test_known_places_client_returns_only_asked_keys_and_none_on_failure():
    def ok(request):
        body = json.loads(request.content)
        assert request.url.path.endswith("/discovery/known-places")
        return httpx.Response(200, json={"data": {"known": [body["place_ids"][0], "node/999999"]}})

    c = KnownPlacesClient(base_url="http://crm.test/api/v1/crm", token="t", user_agent="ua")
    c._client = httpx.Client(transport=httpx.MockTransport(ok))
    assert c.known(["node/2", "node/1"]) == {"node/1"}
    assert c.known([]) == set()

    c._client = httpx.Client(transport=httpx.MockTransport(lambda r: httpx.Response(500)))
    assert c.known(["node/1"]) is None


def test_known_places_client_chunks_at_the_crm_ceiling():
    calls = []

    def handler(request):
        calls.append(len(json.loads(request.content)["place_ids"]))
        return httpx.Response(200, json={"data": {"known": []}})

    c = KnownPlacesClient(base_url="http://crm.test", token="t", user_agent="ua")
    c._client = httpx.Client(transport=httpx.MockTransport(handler))
    c.known([f"node/{i}" for i in range(1, 1202)])
    assert calls == [500, 500, 201]


# -- telemetry -------------------------------------------------------------------------------------


def test_volume_fields_reach_the_metrics_record_and_nothing_changes_without_them():
    provider, _ = volume([element(i) for i in range(1, 11)], known={"node/1"})
    run(provider)
    record = run_metrics.build_record(provider="openai", area="QC", category="dentist", limit=20, commit=True,
                                      duration_ms=1, discovered=10, with_website=0, provider_stats=provider.stats())
    assert record["volume"] is True
    assert record["volume_known"] == 1 and record["volume_new_returned"] == 9
    assert record["volume_raw_truncated"] is False

    plain = run_metrics.build_record(provider="openstreetmap", area="QC", category="dentist", limit=20,
                                     commit=True, duration_ms=1, discovered=3, with_website=0, provider_stats={})
    assert not any(k.startswith("volume") for k in plain)

