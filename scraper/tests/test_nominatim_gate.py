"""The shared Nominatim gate: cross-process request spacing and the area-geocode cache.

Offline. HTTP is a recording stub; the clock and sleep are injected so spacing is asserted exactly,
and a real second PROCESS is used where the claim is about processes.
"""

import multiprocessing
import time

import pytest

from scraper.config import Config
from scraper.nominatim_gate import MIN_INTERVAL_SECONDS, NominatimGate, open_gate
from scraper.providers import build_openstreetmap
from scraper.providers.openstreetmap import OpenStreetMapProvider


class FakeClock:
    def __init__(self, now=1_000.0):
        self.now = now
        self.slept = []

    def time(self):
        return self.now

    def sleep(self, seconds):
        self.slept.append(seconds)
        self.now += seconds


class RecordingClient:
    """Stands in for httpx: records every GET and answers with canned JSON."""

    def __init__(self, results):
        self.results = results
        self.calls = []

    def get(self, url, params=None, **kwargs):
        self.calls.append({"url": url, "params": params or {}})
        return self

    def raise_for_status(self):
        return None

    def json(self):
        return self.results


PH_RESULT = [{"boundingbox": ["10.2", "10.4", "123.8", "124.0"]}]
REVERSE_RESULT = {"address": {"city": "Makati", "state": "Metro Manila", "region": "NCR"}}


def _osm(results, state_dir=None, country_code="PH"):
    provider = OpenStreetMapProvider(
        overpass_url="https://overpass.invalid",
        nominatim_url="https://nominatim.invalid",
        user_agent="test",
        country_code=country_code,
        nominatim_state_dir=state_dir,
    )
    provider._client = RecordingClient(results)
    return provider


# ── spacing ─────────────────────────────────────────────────────────────────────────────────────


def test_consecutive_requests_are_spaced_by_the_policy_interval(tmp_path):
    clock = FakeClock()
    gate = NominatimGate(str(tmp_path), clock=clock.time, sleep=clock.sleep)

    with gate.request_slot():
        pass
    with gate.request_slot():
        pass

    assert clock.slept == [pytest.approx(MIN_INTERVAL_SECONDS)]


def test_a_request_long_after_the_last_one_does_not_wait(tmp_path):
    clock = FakeClock()
    gate = NominatimGate(str(tmp_path), clock=clock.time, sleep=clock.sleep)

    with gate.request_slot():
        pass
    clock.now += 60
    with gate.request_slot():
        pass

    assert clock.slept == []


def test_spacing_is_shared_between_gates_on_the_same_directory(tmp_path):
    """Two engine processes each build their own gate; the file is what they share."""
    clock = FakeClock()
    first = NominatimGate(str(tmp_path), clock=clock.time, sleep=clock.sleep)
    second = NominatimGate(str(tmp_path), clock=clock.time, sleep=clock.sleep)

    with first.request_slot():
        pass
    clock.now += 0.4
    with second.request_slot():
        pass

    assert clock.slept == [pytest.approx(MIN_INTERVAL_SECONDS - 0.4)]


def _hammer(state_dir, n, out):
    gate = NominatimGate(state_dir)
    for _ in range(n):
        with gate.request_slot():
            out.put(time.time())


def test_two_real_processes_never_request_closer_than_the_interval(tmp_path):
    """THE CLAIM THAT MATTERS: two concurrent engine processes, one shared rate. Real processes,
    real flock, real clock — the start of every request is at least the interval after the last."""
    ctx = multiprocessing.get_context("fork")
    out = ctx.Queue()
    procs = [ctx.Process(target=_hammer, args=(str(tmp_path), 3, out)) for _ in range(2)]
    for p in procs:
        p.start()
    for p in procs:
        p.join(timeout=30)
        assert p.exitcode == 0

    starts = sorted(out.get(timeout=5) for _ in range(6))
    gaps = [b - a for a, b in zip(starts, starts[1:])]
    assert min(gaps) >= MIN_INTERVAL_SECONDS - 0.02


# ── area-geocode cache ──────────────────────────────────────────────────────────────────────────


def test_an_area_is_geocoded_once_and_then_answered_from_the_cache(tmp_path):
    first = _osm(PH_RESULT, str(tmp_path))
    bbox = first._geocode_area("General Santos")

    # A NEW provider — i.e. the next engine process — on the same directory.
    second = _osm(PH_RESULT, str(tmp_path))
    assert second._geocode_area("General Santos") == bbox == (10.2, 123.8, 10.4, 124.0)
    assert len(first._client.calls) == 1
    assert second._client.calls == []


def test_the_cache_key_ignores_case_and_surrounding_space(tmp_path):
    _osm(PH_RESULT, str(tmp_path))._geocode_area("General Santos")
    again = _osm(PH_RESULT, str(tmp_path))
    again._geocode_area("  general santos ")
    assert again._client.calls == []


def test_the_country_fence_is_part_of_the_key(tmp_path):
    """A box resolved unrestricted must never answer a fenced lookup, or the fence is bypassed."""
    _osm(PH_RESULT, str(tmp_path), country_code=None)._geocode_area("San Jose")
    fenced = _osm(PH_RESULT, str(tmp_path), country_code="PH")
    fenced._geocode_area("San Jose")
    assert len(fenced._client.calls) == 1
    assert fenced._client.calls[0]["params"]["countrycodes"] == "ph"


def test_an_unresolvable_area_is_not_cached_and_still_raises(tmp_path):
    with pytest.raises(ValueError, match="PH"):
        _osm([], str(tmp_path))._geocode_area("Kota Kinabalu")
    retry = _osm([], str(tmp_path))
    with pytest.raises(ValueError):
        retry._geocode_area("Kota Kinabalu")
    assert len(retry._client.calls) == 1


def test_an_expired_entry_is_geocoded_again(tmp_path):
    clock = FakeClock()
    gate = NominatimGate(str(tmp_path), clock=clock.time, sleep=clock.sleep, cache_ttl_seconds=100)
    gate.store_bbox("k", (1.0, 2.0, 3.0, 4.0))
    assert gate.cached_bbox("k") == (1.0, 2.0, 3.0, 4.0)
    clock.now += 101
    assert gate.cached_bbox("k") is None


def test_a_corrupt_cache_file_is_a_miss_not_a_crash(tmp_path):
    (tmp_path / "geocode-cache.json").write_text("{not json")
    provider = _osm(PH_RESULT, str(tmp_path))
    assert provider._geocode_area("Cebu City") == (10.2, 123.8, 10.4, 124.0)
    assert len(provider._client.calls) == 1


# ── reverse geocoding goes through the same gate ────────────────────────────────────────────────


def test_reverse_geocoding_is_gated_and_still_fills_the_admin_area(tmp_path, monkeypatch):
    from scraper.models import RawBusiness

    slots = []
    provider = _osm(REVERSE_RESULT, str(tmp_path))
    real_slot = provider._nominatim_gate.request_slot

    def counting_slot():
        slots.append(1)
        return real_slot()

    monkeypatch.setattr(provider._nominatim_gate, "request_slot", counting_slot)
    business = RawBusiness(name="X", provider_id="node/1", latitude=14.55, longitude=121.02)
    provider._fill_admin_area(business)

    assert slots == [1]
    assert business.city == "Makati"


# ── off by default: exactly the behaviour that predates the gate ────────────────────────────────


def test_without_a_state_dir_there_is_no_gate_and_no_cache(monkeypatch):
    """Ungated: every lookup is a real request (no cache), paced start-to-start in-process."""
    slept = []
    monkeypatch.setattr("scraper.providers.openstreetmap.time.sleep", slept.append)
    monkeypatch.setattr("scraper.providers.openstreetmap._nominatim_last_start", None)
    provider = _osm(PH_RESULT, None)
    assert provider._nominatim_gate is None

    provider._geocode_area("General Santos")
    provider._geocode_area("General Santos")

    assert len(provider._client.calls) == 2
    # One wait, before the second request; none after either. The stub answers instantly, so the
    # wait is (almost) the whole interval.
    assert slept == [pytest.approx(1.1, abs=0.05)]


def test_the_gate_spaces_request_starts_so_a_slow_request_needs_no_extra_wait(tmp_path):
    clock = FakeClock()
    gate = NominatimGate(str(tmp_path), clock=clock.time, sleep=clock.sleep)

    with gate.request_slot():
        clock.now += 2.0  # a request slower than the interval
    with gate.request_slot():
        pass

    assert clock.slept == []


def test_an_unusable_state_dir_falls_back_to_the_ungated_path(tmp_path, capsys):
    blocker = tmp_path / "a-file"
    blocker.write_text("")
    assert open_gate(str(blocker / "sub")) is None
    assert "Nominatim gate disabled" in capsys.readouterr().err


def test_config_reads_the_state_dir_and_defaults_to_off(monkeypatch):
    monkeypatch.delenv("NOMINATIM_STATE_DIR", raising=False)
    monkeypatch.setattr("scraper.config._load_dotenv", lambda: None)
    assert Config.load().nominatim_state_dir == ""

    monkeypatch.setenv("NOMINATIM_STATE_DIR", "/tmp/somewhere")
    config = Config.load()
    assert config.nominatim_state_dir == "/tmp/somewhere"


def test_the_factory_wires_the_state_dir_into_the_provider(monkeypatch, tmp_path):
    monkeypatch.setattr("scraper.config._load_dotenv", lambda: None)
    monkeypatch.setenv("NOMINATIM_STATE_DIR", str(tmp_path))
    assert build_openstreetmap(Config.load())._nominatim_gate is not None

    monkeypatch.delenv("NOMINATIM_STATE_DIR")
    assert build_openstreetmap(Config.load())._nominatim_gate is None
