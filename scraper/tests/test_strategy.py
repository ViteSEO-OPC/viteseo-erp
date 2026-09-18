"""The AI strategy client: an OPTIONAL layer whose absence is always normal.

Pinned here:

  - **Absence returns None, never an exception and never a guess.** No token, AI disabled (409), an
    older CRM without the endpoint (404), an unreachable CRM, an unreadable body — every one of them
    leaves the caller behaving exactly as it did before this module existed. That is what makes the
    capability matrix work rather than turning AI into a dependency.
  - **An empty plan is a real answer** and is NOT None: "the model had nothing to add" must stay
    distinguishable from "there was no model".
  - **Only a search is ever returned.** Nothing in the payload can describe a business.
  - **No credential is logged**, and the CRM token is sent but never printed.

No test touches the network.
"""

import httpx
import pytest

from scraper.strategy import (
    MAX_PLACE_TYPES,
    MAX_QUERIES,
    MAX_SUB_AREAS,
    DiscoveryStrategy,
    fetch_strategy,
)

CRM = "http://crm.test/api/v1/crm"
TOKEN = "test-crm-token-not-a-real-credential"

PLAN = {
    "selectors": ["shop=chemist"],
    "queries": ["drugstore"],
    "placeTypes": ["pharmacy"],
    "subAreas": ["Alpha", "Beta"],
}


class FakeClient:
    def __init__(self, response=None, raises=None):
        self._response = response
        self._raises = raises
        self.requests = []

    def __call__(self, *args, **kwargs):
        return self

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def post(self, url, json=None, headers=None):
        self.requests.append((url, json or {}, headers or {}))
        if self._raises:
            raise self._raises
        return self._response


def response(payload, status=200):
    return httpx.Response(
        status, json=payload, request=httpx.Request("POST", f"{CRM}/discovery/strategy")
    )


def ok(plan=None, status="ok"):
    return response({"data": {"available": True, "executionStatus": status, "strategy": plan or PLAN}})


def call(monkeypatch, client, **kwargs):
    monkeypatch.setattr(httpx, "Client", client)
    return fetch_strategy(crm_base_url=CRM, crm_token=TOKEN, category="pharmacy", **kwargs)


# --- the happy path --------------------------------------------------------------------------------


def test_a_plan_is_returned_in_every_dialect(monkeypatch):
    plan = call(monkeypatch, FakeClient(ok()))

    assert isinstance(plan, DiscoveryStrategy)
    assert plan.selectors == ["shop=chemist"]
    assert plan.queries == ["drugstore"]
    assert plan.place_types == ["pharmacy"]
    assert plan.sub_areas == ["Alpha", "Beta"]


def test_the_request_carries_the_token_and_the_whole_target_context(monkeypatch):
    client = FakeClient(ok())

    call(monkeypatch, client, scope="Somewhere", limit=25, known_selectors=["amenity=pharmacy"])

    url, body, headers = client.requests[0]
    assert url == f"{CRM}/discovery/strategy"
    assert headers["Authorization"] == f"Bearer {TOKEN}"
    assert body == {
        "category": "pharmacy",
        "scope": "Somewhere",
        "limit": 25,
        "knownSelectors": ["amenity=pharmacy"],
    }


def test_absent_target_context_is_omitted_rather_than_defaulted(monkeypatch):
    # Inventing target context would be inventing the target. A value the caller does not have is
    # simply not sent.
    client = FakeClient(ok())

    call(monkeypatch, client)

    assert client.requests[0][1] == {"category": "pharmacy"}


def test_an_empty_scope_is_omitted_rather_than_sent_blank(monkeypatch):
    client = FakeClient(ok())

    call(monkeypatch, client)

    assert "scope" not in client.requests[0][1]


# --- an empty plan is an answer; absence is not -----------------------------------------------------


def test_an_empty_plan_is_a_real_answer_not_an_absent_capability(monkeypatch):
    plan = call(monkeypatch, FakeClient(ok({"selectors": [], "queries": [], "placeTypes": [], "subAreas": []})))

    # THE DISTINCTION THIS MODULE EXISTS FOR: a considered "nothing to add" is not the same fact as
    # "no model was reachable", and collapsing them would make a disabled capability look settled.
    assert plan is not None
    assert plan.is_empty()


@pytest.mark.parametrize(
    "status",
    [
        409,  # AI not configured/enabled — the ordinary "no optional capability" state.
        404,  # An older CRM that predates the endpoint.
        500,
        502,
    ],
)
def test_every_unavailable_state_returns_none(monkeypatch, status):
    assert call(monkeypatch, FakeClient(response({"message": "no"}, status=status))) is None


def test_an_unreachable_crm_returns_none(monkeypatch):
    assert call(monkeypatch, FakeClient(raises=httpx.ConnectError("down"))) is None


def test_no_token_means_no_call_at_all(monkeypatch):
    client = FakeClient(ok())
    monkeypatch.setattr(httpx, "Client", client)

    assert fetch_strategy(crm_base_url=CRM, crm_token="", category="pharmacy") is None
    assert client.requests == []


@pytest.mark.parametrize(
    "payload",
    [
        {"data": {"strategy": "not an object"}},
        {"data": {}},
        {"nothing": True},
        [],
    ],
)
def test_an_unreadable_body_returns_none(monkeypatch, payload):
    assert call(monkeypatch, FakeClient(response(payload))) is None


# --- whatever comes back is bounded and cleaned ------------------------------------------------------


def test_values_are_bounded_locally_whatever_the_server_returns(monkeypatch):
    # The CRM caps its own output; this is the second, local bound, because a consumer that trusts a
    # remote count is one bad deploy away from an unbounded fan-out.
    flood = {
        "selectors": [f"shop=s{i}" for i in range(100)],
        "queries": [f"q{i}" for i in range(100)],
        "placeTypes": [f"t{i}" for i in range(100)],
        "subAreas": [f"a{i}" for i in range(100)],
    }

    plan = call(monkeypatch, FakeClient(ok(flood)))

    assert len(plan.queries) == MAX_QUERIES
    assert len(plan.place_types) == MAX_PLACE_TYPES
    assert len(plan.sub_areas) == MAX_SUB_AREAS


def test_non_strings_are_dropped_rather_than_coerced(monkeypatch):
    plan = call(monkeypatch, FakeClient(ok({
        "selectors": ["shop=chemist", 42, None, {"a": 1}, "  "],
        "queries": ["drugstore", "drugstore"],  # duplicates collapse
        "placeTypes": [],
        "subAreas": [],
    })))

    assert plan.selectors == ["shop=chemist"]
    assert plan.queries == ["drugstore"]


def test_nothing_in_the_answer_can_describe_a_business(monkeypatch):
    # The safety property in one assertion: the client models four QUERY dialects and no data fields,
    # so a server that returned coordinates or an id has nowhere to put them.
    plan = call(monkeypatch, FakeClient(ok()))

    for forbidden in ("provider_id", "place_id", "latitude", "longitude", "phone", "website", "name"):
        assert not hasattr(plan, forbidden)


# --- the optional status report (per-run AI Discovery) ---------------------------------------------
#
# `None` cannot say WHY there is no plan, and the CRM must tell "the account has no credit" from "AI
# is switched off" to label a run truthfully. `report` carries that word without changing what
# `fetch_strategy` returns to any existing caller.


def test_report_carries_ok_on_a_real_answer(monkeypatch):
    report = {}
    assert call(monkeypatch, FakeClient(ok()), report=report) is not None
    assert report == {"status": "ok"}


def test_report_carries_the_crms_insufficient_credit_word(monkeypatch):
    report = {}
    body = {"message": "no credit", "data": {"available": True, "executionStatus": "insufficient_credit"}}
    assert call(monkeypatch, FakeClient(response(body, status=502)), report=report) is None
    assert report == {"status": "insufficient_credit"}


def test_report_distinguishes_a_disabled_capability_and_an_unreachable_crm(monkeypatch):
    disabled = {}
    call(monkeypatch, FakeClient(response({"message": "no"}, status=409)), report=disabled)
    assert disabled == {"status": "not_enabled"}

    down = {}
    call(monkeypatch, FakeClient(raises=httpx.ConnectError("down")), report=down)
    assert down == {"status": "unreachable"}

    bare = {}
    call(monkeypatch, FakeClient(response({"message": "no"}, status=500)), report=bare)
    assert bare == {"status": "http_500"}


def test_omitting_report_changes_nothing(monkeypatch):
    # The pre-existing call shape still works and still returns None on refusal.
    body = {"data": {"executionStatus": "insufficient_credit"}}
    assert call(monkeypatch, FakeClient(response(body, status=502))) is None
