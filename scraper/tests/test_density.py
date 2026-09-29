"""The national density probe and start-to-start Nominatim spacing. Offline: HTTP is a stub."""

import json

import httpx
import pytest

import scraper.providers.openstreetmap as osm
from scraper.cli import main
from scraper.density import EXIT_PROBE_FAILED, EXIT_UNSUPPORTED, run_density
from scraper.providers.openstreetmap import OpenStreetMapProvider

# ── fixtures ────────────────────────────────────────────────────────────────────────────────────


class Resp:
    def __init__(self, status=200, payload=None):
        self.status_code = status
        self._payload = payload if payload is not None else {}
        self.content = json.dumps(self._payload).encode()

    def json(self):
        return self._payload

    def raise_for_status(self):
        if self.status_code >= 400:
            raise httpx.HTTPStatusError("err", request=None, response=None)


class PostClient:
    """Records every POST (query + kwargs) and answers from a queue of responses."""

    def __init__(self, responses):
        self.responses = list(responses)
        self.posts = []
        self.gets = []

    def post(self, url, content=None, **kwargs):
        self.posts.append({"query": content, "kwargs": kwargs})
        return self.responses.pop(0)

    def get(self, url, params=None, **kwargs):
        self.gets.append(url)
        return Resp(200, [{"boundingbox": ["1", "2", "3", "4"]}])

    def close(self):
        pass


ELEMENTS = {"elements": [
    {"type": "node", "id": 1, "lat": 14.5512345678, "lon": 121.0198765432},
    {"type": "way", "id": 2, "center": {"lat": 10.3, "lon": 123.9}},
    {"type": "node", "id": 3},  # no position: ignored
]}


def _provider(responses):
    p = OpenStreetMapProvider(overpass_url="https://overpass.invalid", nominatim_url="https://nominatim.invalid",
                              user_agent="test")
    p._client = PostClient(responses)
    return p


@pytest.fixture(autouse=True)
def no_real_sleep(monkeypatch):
    slept = []
    monkeypatch.setattr(osm.time, "sleep", slept.append)
    return slept


# ── the query ───────────────────────────────────────────────────────────────────────────────────


def test_the_probe_is_one_lean_named_query_over_the_country_area():
    p = _provider([Resp(200, ELEMENTS)])
    points = p.national_points([("amenity", "restaurant"), ("amenity", "fast_food")], country_code="ph")

    assert len(p._client.posts) == 1
    q = p._client.posts[0]["query"]
    assert 'area["ISO3166-1"="PH"][admin_level=2]' in q
    for kv in ('["amenity"="restaurant"][name]', '["amenity"="fast_food"][name]'):
        assert f"node{kv}(area.c);" in q and f"way{kv}(area.c);" in q
    assert q.endswith("out skel center qt;")
    # Nodes by position, ways by centre; an element with neither is not a point.
    assert points == [(14.55123, 121.01988), (10.3, 123.9)]


def test_the_probe_gets_a_longer_http_timeout_than_the_default_client():
    p = _provider([Resp(200, ELEMENTS)])
    p.national_points([("amenity", "dentist")], country_code="PH")
    assert p._client.posts[0]["kwargs"]["timeout"] == osm.NATIONAL_PROBE_TIMEOUT_SECONDS


def test_the_default_overpass_path_still_sends_no_timeout_override():
    p = _provider([Resp(200, {"elements": []})])
    p._overpass([("amenity", "cafe")], (1.0, 2.0, 3.0, 4.0), 20)
    assert "timeout" not in p._client.posts[0]["kwargs"]


def test_the_probe_retries_a_busy_server_like_every_other_overpass_call(no_real_sleep):
    p = _provider([Resp(504), Resp(200, ELEMENTS)])
    assert len(p.national_points([("amenity", "dentist")], country_code="PH")) == 2
    assert no_real_sleep == [5.0]


def test_a_country_that_is_not_two_letters_is_refused_before_any_request():
    p = _provider([])
    with pytest.raises(ValueError):
        p.national_points([("amenity", "dentist")], country_code='PH"];out;')
    assert p._client.posts == []


# ── the command ─────────────────────────────────────────────────────────────────────────────────


def _patch_provider(monkeypatch, responses):
    provider = _provider(responses)
    monkeypatch.setattr("scraper.density.build_openstreetmap", lambda config: provider)
    return provider


def test_the_command_prints_points_as_json_and_exits_zero(monkeypatch, capsys):
    _patch_provider(monkeypatch, [Resp(200, ELEMENTS)])
    assert main(["density", "--category", "restaurant", "--country", "PH"]) == 0
    out = json.loads(capsys.readouterr().out)
    assert out["count"] == 2
    assert out["points"] == [[14.55123, 121.01988], [10.3, 123.9]]
    assert out["selectors"] == ["amenity=restaurant"]


def test_an_unsupported_niche_exits_unsupported_and_asks_overpass_nothing(monkeypatch, capsys):
    provider = _patch_provider(monkeypatch, [])
    assert main(["density", "--category", "artisanal kombucha", "--country", "PH"]) == EXIT_UNSUPPORTED
    assert provider._client.posts == []
    assert capsys.readouterr().out == ""


def test_an_exhausted_retry_exits_probe_failed_with_nothing_on_stdout(monkeypatch, capsys):
    _patch_provider(monkeypatch, [Resp(504)] * 4)
    assert main(["density", "--category", "dentist", "--country", "PH"]) == EXIT_PROBE_FAILED
    assert capsys.readouterr().out == ""


def test_a_transport_error_exits_probe_failed(monkeypatch):
    provider = _patch_provider(monkeypatch, [])

    def boom(*a, **k):
        raise httpx.ReadTimeout("slow")

    provider._client.post = boom
    assert run_density(object(), "dentist", "PH") == EXIT_PROBE_FAILED


# ── start-to-start Nominatim spacing (the ungated, default path) ────────────────────────────────


class Clock:
    def __init__(self):
        self.now = 100.0

    def monotonic(self):
        return self.now


@pytest.fixture
def clock(monkeypatch, no_real_sleep):
    c = Clock()
    monkeypatch.setattr(osm.time, "monotonic", c.monotonic)
    monkeypatch.setattr(osm, "_nominatim_last_start", None)

    def sleep(seconds):
        no_real_sleep.append(seconds)
        c.now += seconds

    monkeypatch.setattr(osm.time, "sleep", sleep)
    return c


def test_requests_are_spaced_start_to_start_not_after_each_response(clock, no_real_sleep):
    p = _provider([])
    p._geocode_area("A")          # starts at t=100
    clock.now += 0.5              # the request itself took 0.5s
    p._geocode_area("B")          # must start at t>=101.1: waits only the remaining 0.6s
    assert no_real_sleep == [pytest.approx(0.6)]


def test_a_slow_response_leaves_no_extra_wait(clock, no_real_sleep):
    p = _provider([])
    p._geocode_area("A")
    clock.now += 2.0              # slower than the interval
    p._geocode_area("B")
    assert no_real_sleep == []


def test_spacing_is_process_wide_across_provider_instances(clock, no_real_sleep):
    _provider([])._geocode_area("A")
    clock.now += 0.2
    _provider([])._geocode_area("B")
    assert no_real_sleep == [pytest.approx(0.9)]


def test_reverse_geocoding_shares_the_same_spacing(clock, no_real_sleep):
    from scraper.models import RawBusiness

    p = _provider([])
    p._client.get = lambda url, params=None, **k: Resp(200, {"address": {"city": "Makati"}})
    p._fill_admin_area(RawBusiness(name="A", provider_id="node/1", latitude=1.0, longitude=2.0))
    clock.now += 0.3
    p._fill_admin_area(RawBusiness(name="B", provider_id="node/2", latitude=1.0, longitude=2.0))
    assert no_real_sleep == [pytest.approx(0.8)]


def test_the_process_does_not_exit_inside_the_interval_of_its_last_request(clock, no_real_sleep):
    """The next task is a new process; it must not be able to start within 1.1s of this one's last."""
    _provider([])._geocode_area("A")
    clock.now += 0.4
    osm._drain_nominatim_interval()
    assert no_real_sleep == [pytest.approx(0.7)]


def test_a_process_that_never_called_nominatim_exits_at_once(clock, no_real_sleep):
    osm._drain_nominatim_interval()
    assert no_real_sleep == []
