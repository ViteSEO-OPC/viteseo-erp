"""The AI discovery provider: proposal only, never authoritative — and never silently failing.

Three things are pinned here, each from the direction it would actually break:

  - **A language model's output cannot become a lead without OSM confirming it.** Identity always
    comes from a real OSM element; a name OSM cannot confirm is dropped.
  - **The credential is not in this process.** The provider authenticates to the CRM, which holds the
    OpenAI key and makes the model call. There is no OpenAI key here to leak.
  - **A FAILED CALL IS NOT AN EMPTY RESULT.** A rejected credential and a genuinely empty answer are
    both zero names. Reporting them identically would let a broken integration masquerade as an
    honest quiet run indefinitely, so every failure carries a status and only `ok` means the list is
    real.

No test touches the network: the CRM client and the OSM lookup are both stubbed.
"""

import json

import httpx
import pytest

from scraper.models import RawBusiness
from scraper.providers.openai_discovery import (
    EXECUTION_CRM_UNREACHABLE,
    EXECUTION_INVALID_RESPONSE,
    EXECUTION_NOT_EFFECTIVE,
    EXECUTION_OK,
    OpenAiDiscoveryProvider,
)


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
    """A provider whose CRM POST is replaced by `respond(url, kwargs)`."""
    fake_osm = FakeOsm(known)
    provider = OpenAiDiscoveryProvider(
        crm_base_url="http://crm.test/api/v1/crm",
        crm_token="test-crm-token",
        timeout=5,
        osm=fake_osm,
    )
    monkeypatch.setattr(provider._client, "post", lambda url, **kw: respond(url, kw))
    return provider, fake_osm


def crm_ok(names: list, usage: dict | None = None, status: int = 200) -> httpx.Response:
    body = {
        "data": {
            "effectiveProvider": "openai",
            "executionStatus": EXECUTION_OK,
            "errorReason": None,
            "proposals": names,
            "usage": usage or {"model": "gpt-4o-mini", "prompt_tokens": 120, "completion_tokens": 40},
        }
    }
    return httpx.Response(status, json=body, request=httpx.Request("POST", "http://crm.test"))


def crm_failure(execution_status: str, error: str = "boom", http: int = 502) -> httpx.Response:
    body = {
        "message": "The discovery provider could not be reached or refused the request.",
        "data": {
            "effectiveProvider": "openai",
            "executionStatus": execution_status,
            "errorReason": error,
            "proposals": [],
            "usage": {},
        },
    }
    return httpx.Response(http, json=body, request=httpx.Request("POST", "http://crm.test"))


# --- the capability -------------------------------------------------------------


def test_only_osm_confirmed_proposals_become_candidates(monkeypatch):
    provider, _ = build(
        monkeypatch,
        respond=lambda url, kw: crm_ok(["Kopi Roasters", "Invented Bistro"]),
        known=[osm_business("Kopi Roasters", "node/1")],
    )

    found = provider.discover(area="Makati", category="cafe", limit=10)

    assert [b.provider_id for b in found] == ["node/1"]
    assert provider.stats()["proposed"] == 2
    assert provider.stats()["resolved"] == 1
    assert provider.stats()["resolution_rate"] == 0.5
    assert provider.stats()["execution_status"] == EXECUTION_OK


def test_every_returned_field_comes_from_osm_not_the_model(monkeypatch):
    # If the CRM (or the model behind it) volunteers anything beyond names, it must not survive.
    def respond(url, kw):
        body = {
            "data": {
                "executionStatus": EXECUTION_OK,
                "proposals": ["Kopi Roasters"],
                "coordinates": [{"lat": 0.0, "lon": 0.0}],
                "provider_id": "node/999",
                "usage": {},
            }
        }
        return httpx.Response(200, json=body, request=httpx.Request("POST", "http://crm.test"))

    provider, _ = build(monkeypatch, respond=respond, known=[osm_business("Kopi Roasters", "node/1")])
    found = provider.discover(area="Makati", category="cafe", limit=10)

    assert found[0].provider_id == "node/1"   # not node/999
    assert found[0].latitude == 14.5          # not 0.0


def test_the_callers_limit_still_binds_after_oversampling(monkeypatch):
    words = ["Alpha", "Bravo", "Charlie", "Delta", "Echo", "Foxtrot", "Golf", "Hotel", "India", "Juliet"]
    known = [osm_business(f"{w} Cafe", f"node/{i}") for i, w in enumerate(words)]
    provider, _ = build(
        monkeypatch, respond=lambda url, kw: crm_ok([b.name for b in known]), known=known
    )

    # Oversampling widens the SEARCH, never the import.
    assert len(provider.discover(area="Makati", category="cafe", limit=3)) == 3


def test_proposals_are_asked_for_beyond_the_limit(monkeypatch):
    seen = {}

    def respond(url, kw):
        seen["payload"] = kw["json"]
        return crm_ok([])

    provider, _ = build(monkeypatch, respond=respond)
    provider.discover(area="Makati", category="cafe", limit=5)

    assert seen["payload"]["count"] > 5


def test_token_usage_is_reported_for_cost_comparison(monkeypatch):
    provider, _ = build(
        monkeypatch,
        respond=lambda url, kw: crm_ok(
            ["Kopi Roasters"], {"model": "gpt-4o-mini", "prompt_tokens": 210, "completion_tokens": 25}
        ),
        known=[osm_business("Kopi Roasters", "node/1")],
    )
    provider.discover(area="Makati", category="cafe", limit=5)

    assert provider.stats()["prompt_tokens"] == 210
    assert provider.stats()["completion_tokens"] == 25
    assert provider.stats()["model"] == "gpt-4o-mini"


# --- the credential is not in this process --------------------------------------


def test_the_provider_authenticates_to_the_crm_and_holds_no_openai_key(monkeypatch):
    seen = {}

    def respond(url, kw):
        seen["url"] = url
        seen["headers"] = kw.get("headers", {})
        return crm_ok([])

    provider, _ = build(monkeypatch, respond=respond)
    provider.discover(area="Makati", category="cafe", limit=5)

    assert seen["url"].endswith("/discovery/proposals")
    assert seen["headers"]["Authorization"] == "Bearer test-crm-token"
    # There is no OpenAI credential in this process to leak, by construction.
    assert not hasattr(provider, "api_key")
    assert not hasattr(provider, "model")


def test_a_missing_crm_token_fails_loudly_rather_than_falling_back():
    # A silent fall back to OSM would label a plain OSM run as `openai` in the metrics.
    with pytest.raises(ValueError, match="CRM_TOKEN"):
        OpenAiDiscoveryProvider(
            crm_base_url="http://crm.test", crm_token="", timeout=5, osm=FakeOsm()
        )


# --- a genuine empty answer -----------------------------------------------------


def test_an_empty_but_successful_answer_is_a_successful_run(monkeypatch):
    # The model legitimately found nothing. That is an ordinary discovery outcome and must NOT be
    # reported as a provider failure — the mirror image of the tests below.
    provider, _ = build(monkeypatch, respond=lambda url, kw: crm_ok([]))

    assert provider.discover(area="Nowhere", category="cafe", limit=5) == []
    assert provider.stats()["execution_status"] == EXECUTION_OK
    assert provider.stats()["error_reason"] is None
    assert provider.stats()["proposed"] == 0


# --- a failure is NOT an empty result -------------------------------------------


@pytest.mark.parametrize(
    "status",
    ["auth_failed", "rate_limited", "unreachable", "invalid_response", "not_configured"],
)
def test_an_upstream_failure_is_reported_as_a_failure_not_as_zero_candidates(monkeypatch, status):
    provider, fake_osm = build(
        monkeypatch,
        respond=lambda url, kw: crm_failure(status, error="http_401"),
        known=[osm_business("Kopi Roasters", "node/1")],
    )

    found = provider.discover(area="Makati", category="cafe", limit=5)

    assert found == []
    # THE DISTINCTION. Zero candidates, but explicitly NOT a successful run.
    assert provider.stats()["execution_status"] == status
    assert provider.stats()["execution_status"] != EXECUTION_OK
    assert provider.stats()["error_reason"] == "http_401"
    # And no Overpass spend on a batch that was never obtained.
    assert fake_osm.asked == []


def test_a_rejected_credential_is_distinguishable_from_an_empty_answer(monkeypatch):
    # Both produce zero candidates. The stats must tell them apart — this is the whole correction.
    rejected, _ = build(monkeypatch, respond=lambda url, kw: crm_failure("auth_failed", "http_401"))
    empty, _ = build(monkeypatch, respond=lambda url, kw: crm_ok([]))

    rejected.discover(area="Makati", category="cafe", limit=5)
    empty.discover(area="Makati", category="cafe", limit=5)

    assert rejected.stats()["execution_status"] == "auth_failed"
    assert empty.stats()["execution_status"] == EXECUTION_OK
    assert rejected.stats()["execution_status"] != empty.stats()["execution_status"]


def test_a_crm_refusal_because_ai_is_not_effective_is_reported(monkeypatch):
    # 409: the CRM enforcing the same guard the settings page shows. A configuration state, surfaced
    # rather than swallowed.
    def respond(url, kw):
        return httpx.Response(
            409,
            json={"data": {"effectiveProvider": "openstreetmap", "reason": "provider_disabled", "proposals": []}},
            request=httpx.Request("POST", "http://crm.test"),
        )

    provider, _ = build(monkeypatch, respond=respond)

    assert provider.discover(area="Makati", category="cafe", limit=5) == []
    assert provider.stats()["execution_status"] == EXECUTION_NOT_EFFECTIVE


@pytest.mark.parametrize(
    "exc",
    [httpx.ConnectTimeout("timed out"), httpx.ReadTimeout("slow"), httpx.ConnectError("refused")],
    ids=["connect timeout", "read timeout", "connection refused"],
)
def test_an_unreachable_crm_is_a_failure_not_an_empty_run(monkeypatch, exc):
    def respond(url, kw):
        raise exc

    provider, _ = build(monkeypatch, respond=respond)

    # No exception escapes — a scheduled sweep must not abort — but the run is marked failed.
    assert provider.discover(area="Makati", category="cafe", limit=5) == []
    assert provider.stats()["execution_status"] == EXECUTION_CRM_UNREACHABLE


@pytest.mark.parametrize(
    "body",
    [
        {"unexpected": True},
        {"data": {"executionStatus": EXECUTION_OK}},          # no proposals key at all
        {"data": {"executionStatus": EXECUTION_OK, "proposals": "not a list"}},
    ],
    ids=["no data envelope", "missing proposals", "proposals not a list"],
)
def test_an_unreadable_crm_response_is_a_failure_not_an_empty_run(monkeypatch, body):
    provider, _ = build(
        monkeypatch,
        respond=lambda url, kw: httpx.Response(
            200, json=body, request=httpx.Request("POST", "http://crm.test")
        ),
    )

    assert provider.discover(area="Makati", category="cafe", limit=5) == []
    assert provider.stats()["execution_status"] == EXECUTION_INVALID_RESPONSE


def test_junk_entries_beside_usable_names_do_not_fail_the_run(monkeypatch):
    # A well-formed response with some unusable entries is still a SUCCESS — the usable names survive
    # and the run is not marked failed. Only an unreadable response is a failure.
    def respond(url, kw):
        body = {
            "data": {
                "executionStatus": EXECUTION_OK,
                "proposals": ["Kopi Roasters", None, 42, "  "],
                "usage": {},
            }
        }
        return httpx.Response(200, json=body, request=httpx.Request("POST", "http://crm.test"))

    provider, _ = build(monkeypatch, respond=respond, known=[osm_business("Kopi Roasters", "node/1")])

    assert len(provider.discover(area="Makati", category="cafe", limit=5)) == 1
    assert provider.stats()["execution_status"] == EXECUTION_OK


# --- provenance and cleanup -----------------------------------------------------


def test_provenance_is_the_provider_name():
    provider = OpenAiDiscoveryProvider(
        crm_base_url="http://crm.test", crm_token="t", timeout=5, osm=FakeOsm()
    )
    assert provider.name == "openai"


def test_closing_releases_the_osm_client_too(monkeypatch):
    provider, fake_osm = build(monkeypatch, respond=lambda url, kw: crm_ok([]))
    provider.close()
    assert fake_osm.closed
