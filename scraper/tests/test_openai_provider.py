"""The OpenAI discovery provider — proposal only, never authoritative.

Two things are being pinned. First, that a language model's output cannot become a lead without OSM
confirming it. Second, that every way the model can fail — down, slow, rate-limited, or answering
with nonsense — produces NO candidates rather than bad ones.

No network: the OpenAI client and the OSM lookup are both stubbed.
"""

import json

import httpx
import pytest

from scraper.models import RawBusiness
from scraper.providers.openai_discovery import OpenAiDiscoveryProvider


class FakeOsm:
    """Stands in for OpenStreetMapProvider: records the names asked about, returns what it 'knows'."""

    def __init__(self, known: list[RawBusiness] | None = None) -> None:
        self.known = known or []
        self.asked: list[list[str]] = []
        self.closed = False

    def find_by_name(self, names, *, area, limit=200):
        self.asked.append(list(names))
        wanted = {n.lower() for n in names}
        return [b for b in self.known if b.name.lower() in wanted]

    def close(self):
        self.closed = True


def osm_business(name: str, provider_id: str, website: str | None = None) -> RawBusiness:
    return RawBusiness(
        provider_id=provider_id, name=name, latitude=14.5, longitude=121.0, website=website
    )


def build(monkeypatch, *, respond, known=None) -> tuple[OpenAiDiscoveryProvider, FakeOsm]:
    """A provider whose HTTP POST is replaced by `respond(url, json)`."""
    fake_osm = FakeOsm(known)
    provider = OpenAiDiscoveryProvider(
        api_key="test-key",
        model="gpt-4o-mini",
        base_url="https://api.openai.com/v1",
        timeout=5,
        osm=fake_osm,
    )
    monkeypatch.setattr(provider._client, "post", lambda url, **kw: respond(url, kw))
    return provider, fake_osm


def ok(names: list, usage: dict | None = None) -> httpx.Response:
    body = {
        "choices": [{"message": {"content": json.dumps({"businesses": names})}}],
        "usage": usage or {"prompt_tokens": 120, "completion_tokens": 40},
    }
    return httpx.Response(200, json=body, request=httpx.Request("POST", "https://api.openai.com"))


# --- the capability -------------------------------------------------------------


def test_only_osm_confirmed_proposals_become_candidates(monkeypatch):
    provider, fake_osm = build(
        monkeypatch,
        respond=lambda url, kw: ok(["Kopi Roasters", "Invented Bistro"]),
        known=[osm_business("Kopi Roasters", "node/1")],
    )

    found = provider.discover(area="Makati", category="cafe", limit=10)

    assert [b.provider_id for b in found] == ["node/1"]
    assert provider.stats()["proposed"] == 2
    assert provider.stats()["resolved"] == 1
    assert provider.stats()["resolution_rate"] == 0.5


def test_every_returned_field_comes_from_osm_not_the_model(monkeypatch):
    # The model is asked for names only; if it volunteers anything else it must not survive.
    def respond(url, kw):
        body = {
            "choices": [{"message": {"content": json.dumps({
                "businesses": ["Kopi Roasters"],
                "coordinates": [{"lat": 0.0, "lon": 0.0}],
                "provider_id": "node/999",
            })}}],
            "usage": {},
        }
        return httpx.Response(200, json=body, request=httpx.Request("POST", "https://x"))

    provider, _ = build(monkeypatch, respond=respond, known=[osm_business("Kopi Roasters", "node/1")])
    found = provider.discover(area="Makati", category="cafe", limit=10)

    assert found[0].provider_id == "node/1"   # not node/999
    assert found[0].latitude == 14.5          # not 0.0


def test_the_callers_limit_still_binds_after_oversampling(monkeypatch):
    # Distinct identity-bearing names: "Cafe 0".."Cafe 9" all reduce to the single token "cafe",
    # which the resolver would correctly refuse as ambiguous rather than resolve.
    words = ["Alpha", "Bravo", "Charlie", "Delta", "Echo", "Foxtrot", "Golf", "Hotel", "India", "Juliet"]
    known = [osm_business(f"{w} Cafe", f"node/{i}") for i, w in enumerate(words)]
    provider, _ = build(
        monkeypatch, respond=lambda url, kw: ok([b.name for b in known]), known=known
    )

    found = provider.discover(area="Makati", category="cafe", limit=3)

    # Oversampling widens the search, never what reaches the CRM.
    assert len(found) == 3


def test_proposals_are_asked_for_beyond_the_limit(monkeypatch):
    seen = {}

    def respond(url, kw):
        seen["payload"] = kw["json"]
        return ok([])

    provider, _ = build(monkeypatch, respond=respond)
    provider.discover(area="Makati", category="cafe", limit=5)

    asked = json.loads(seen["payload"]["messages"][1]["content"])["max_results"]
    assert asked > 5


def test_sampling_is_deterministic(monkeypatch):
    seen = {}

    def respond(url, kw):
        seen["payload"] = kw["json"]
        return ok([])

    provider, _ = build(monkeypatch, respond=respond)
    provider.discover(area="Makati", category="cafe", limit=5)

    assert seen["payload"]["temperature"] == 0


def test_token_usage_is_reported_for_cost_comparison(monkeypatch):
    provider, _ = build(
        monkeypatch,
        respond=lambda url, kw: ok(["Kopi Roasters"], {"prompt_tokens": 210, "completion_tokens": 25}),
        known=[osm_business("Kopi Roasters", "node/1")],
    )
    provider.discover(area="Makati", category="cafe", limit=5)

    assert provider.stats()["prompt_tokens"] == 210
    assert provider.stats()["completion_tokens"] == 25
    assert provider.stats()["model"] == "gpt-4o-mini"


# --- malformed and hostile model output -----------------------------------------


@pytest.mark.parametrize(
    "content",
    [
        "not json at all",
        '{"businesses": "not a list"}',
        '{"wrong_key": ["Kopi Roasters"]}',
        "[]",                                  # a list, not the expected object
        '{"businesses": [null, 42, {"a": 1}]}',  # right shape, unusable entries
        '{"businesses": []}',
    ],
    ids=["plain text", "not a list", "missing key", "not an object", "junk entries", "empty"],
)
def test_malformed_model_output_yields_no_candidates(monkeypatch, content):
    def respond(url, kw):
        body = {"choices": [{"message": {"content": content}}], "usage": {}}
        return httpx.Response(200, json=body, request=httpx.Request("POST", "https://x"))

    provider, fake_osm = build(monkeypatch, respond=respond, known=[osm_business("Kopi", "node/1")])

    assert provider.discover(area="Makati", category="cafe", limit=5) == []
    assert fake_osm.asked == []  # nothing worth looking up, so no Overpass spend either


def test_usable_names_survive_junk_entries_beside_them(monkeypatch):
    def respond(url, kw):
        body = {"choices": [{"message": {"content": json.dumps(
            {"businesses": ["Kopi Roasters", None, 42, "  "]}
        )}}], "usage": {}}
        return httpx.Response(200, json=body, request=httpx.Request("POST", "https://x"))

    provider, _ = build(monkeypatch, respond=respond, known=[osm_business("Kopi Roasters", "node/1")])

    assert len(provider.discover(area="Makati", category="cafe", limit=5)) == 1


# --- API failure modes ----------------------------------------------------------


@pytest.mark.parametrize("status", [400, 401, 429, 500, 503])
def test_non_2xx_responses_yield_no_candidates(monkeypatch, status):
    def respond(url, kw):
        return httpx.Response(status, json={"error": "nope"}, request=httpx.Request("POST", "https://x"))

    provider, fake_osm = build(monkeypatch, respond=respond)

    assert provider.discover(area="Makati", category="cafe", limit=5) == []
    assert fake_osm.asked == []


@pytest.mark.parametrize(
    "exc",
    [httpx.ConnectTimeout("timed out"), httpx.ReadTimeout("slow"), httpx.ConnectError("refused")],
    ids=["connect timeout", "read timeout", "connection refused"],
)
def test_transport_failures_yield_no_candidates(monkeypatch, exc):
    def respond(url, kw):
        raise exc

    provider, _ = build(monkeypatch, respond=respond)

    # No exception escapes: an unreachable model is an empty discovery, which run_discovery already
    # handles as "nothing discovered". It must never abort a scheduled run.
    assert provider.discover(area="Makati", category="cafe", limit=5) == []


def test_a_response_missing_the_expected_shape_yields_no_candidates(monkeypatch):
    def respond(url, kw):
        return httpx.Response(200, json={"unexpected": True}, request=httpx.Request("POST", "https://x"))

    provider, _ = build(monkeypatch, respond=respond)

    assert provider.discover(area="Makati", category="cafe", limit=5) == []


# --- configuration --------------------------------------------------------------


def test_a_missing_api_key_fails_loudly_rather_than_falling_back():
    # A silent fallback to OSM would label a plain OSM run as `openai` in the metrics and quietly
    # corrupt the comparison this provider exists to enable.
    with pytest.raises(ValueError, match="OPENAI_API_KEY"):
        OpenAiDiscoveryProvider(
            api_key="", model="gpt-4o-mini", base_url="https://api.openai.com/v1",
            timeout=5, osm=FakeOsm(),
        )


def test_provenance_is_the_provider_name():
    provider = OpenAiDiscoveryProvider(
        api_key="k", model="m", base_url="https://x", timeout=5, osm=FakeOsm()
    )
    assert provider.name == "openai"


def test_closing_releases_the_osm_client_too(monkeypatch):
    provider, fake_osm = build(monkeypatch, respond=lambda url, kw: ok([]))
    provider.close()
    assert fake_osm.closed
