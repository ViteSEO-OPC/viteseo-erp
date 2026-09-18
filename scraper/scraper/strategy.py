"""The CRM's discovery-strategy capability, as the scraper sees it.

    scraper  ->  CRM /discovery/strategy  ->  (the CRM holds the key)  ->  model  ->  a SEARCH PLAN

WHAT COMES BACK IS NEVER DATA. Selectors, query phrases, place types and sub-area names: every one
describes how to look, and none of them describes a business. Whatever arrives here is executed by a
deterministic provider which supplies the identity, the coordinates and the contact fields — exactly
as the name lane has always worked. A wrong answer therefore costs an empty search, never a fake lead.

IT IS OPTIONAL, AND ABSENCE IS NORMAL. No credential, AI disabled, no credit, an unreachable CRM, a
404 from an older CRM that predates the endpoint — every one of them returns ``None``, and every
caller must behave exactly as it did before this module existed. That is what makes the capability
matrix work: AI is an enhancement layered onto a provider that already functions without it, never a
dependency the provider acquires.

NO CREDENTIAL IS INVOLVED IN EITHER DIRECTION. The CRM holds the model key and makes the call; this
module sends only the scraper's own CRM token, which it already uses to ingest.
"""

from __future__ import annotations

import sys
from dataclasses import dataclass, field
from typing import Any, Optional

import httpx

#: The strategy endpoint, relative to the CRM base URL the scraper already targets.
STRATEGY_PATH = "/discovery/strategy"

#: Ceilings applied to whatever comes back. The CRM caps its own output; this is the second, local
#: bound, because a consumer that trusts a remote count is one bad deploy away from an unbounded loop.
MAX_QUERIES = 8
MAX_PLACE_TYPES = 8
MAX_SELECTORS = 8
MAX_SUB_AREAS = 16


@dataclass(frozen=True)
class DiscoveryStrategy:
    """A search plan, in whichever dialects the caller can execute.

    Every field is optional and an empty one means "no advice", never "search nothing". A caller
    consults the fields it understands and ignores the rest: an OSM sweep reads ``selectors``, a
    structured places provider reads ``queries``/``place_types``, and a partitioner reads
    ``sub_areas``. That is what makes one capability serve every provider.
    """

    selectors: list[str] = field(default_factory=list)
    queries: list[str] = field(default_factory=list)
    place_types: list[str] = field(default_factory=list)
    sub_areas: list[str] = field(default_factory=list)
    status: str = "ok"

    def is_empty(self) -> bool:
        return not (self.selectors or self.queries or self.place_types or self.sub_areas)


def _clean(values: Any, *, cap: int) -> list[str]:
    """Trimmed, de-duplicated, bounded strings. Anything else in the payload is dropped, not coerced."""
    if not isinstance(values, list):
        return []
    out: list[str] = []
    for value in values:
        if not isinstance(value, str):
            continue
        text = value.strip()
        if text and text not in out:
            out.append(text)
        if len(out) >= cap:
            break
    return out


def _error_status(response: httpx.Response) -> str:
    """The CRM's status word for a refused strategy call, or ``http_<code>`` when it gave none."""
    try:
        body: Any = response.json()
    except ValueError:
        body = None
    data = body.get("data") if isinstance(body, dict) else None
    status = data.get("executionStatus") if isinstance(data, dict) else None
    if isinstance(status, str) and status.strip():
        return status.strip()[:64]
    return f"http_{response.status_code}"


def fetch_strategy(
    *,
    crm_base_url: str,
    crm_token: str,
    category: str,
    scope: str = "",
    limit: Optional[int] = None,
    known_selectors: Optional[list[str]] = None,
    timeout: float = 30.0,
    user_agent: str = "CRM-Enterprise-Scraper/1.0",
    quiet: bool = False,
    report: Optional[dict[str, Any]] = None,
) -> Optional[DiscoveryStrategy]:
    """Ask the CRM how to search. ``None`` whenever the capability is not available.

    Never raises and never guesses. The distinction that matters is between "the CRM answered and the
    plan is empty" (a real answer — return the empty strategy) and "there was no answer" (return
    None): a caller must be able to tell an AI that had nothing to add from an AI that was never
    reachable, or a disabled capability looks identical to a well-considered no-op forever.

    ``report``, when given, receives ``status``: how the planning call ended, as a status word
    (``ok``, ``insufficient_credit``, ``not_enabled``, ``unreachable``, ...). Optional and write-only,
    so every existing caller and the return contract are unchanged; it exists because ``None`` alone
    cannot tell "the account has no credit" from "AI is switched off".
    """
    def _report(status: str) -> None:
        if report is not None:
            report["status"] = status

    if not crm_token:
        return None

    url = f"{crm_base_url.rstrip('/')}{STRATEGY_PATH}"
    # THE REAL TARGET CONTEXT, not just a category word: the area the run is actually searching and
    # how many businesses it wants. Each is sent only when the caller genuinely has it — an absent
    # value is omitted rather than defaulted, because inventing target context would be inventing the
    # target.
    payload: dict[str, Any] = {"category": category}
    if scope:
        payload["scope"] = scope
    if limit is not None:
        payload["limit"] = limit
    if known_selectors:
        payload["knownSelectors"] = list(known_selectors)

    try:
        with httpx.Client(timeout=timeout) as client:
            response = client.post(
                url,
                json=payload,
                headers={
                    "Authorization": f"Bearer {crm_token}",
                    "Accept": "application/json",
                    "Content-Type": "application/json",
                    "User-Agent": user_agent,
                },
            )
    except httpx.HTTPError as exc:
        if not quiet:
            print(f"  discovery strategy unavailable ({type(exc).__name__}).", file=sys.stderr)
        _report("unreachable")
        return None

    # 409 = AI is not configured/enabled. That is the ordinary "no optional capability" state and it
    # is reported at most as a note: a run without AI is a supported configuration, not a problem.
    if response.status_code == 409:
        if not quiet:
            print("  discovery strategy unavailable (AI capability not enabled).", file=sys.stderr)
        _report("not_enabled")
        return None

    if response.status_code >= 400:
        # The status only — never the body, which can quote an authenticated request.
        if not quiet:
            print(
                f"  discovery strategy unavailable (HTTP {response.status_code}).",
                file=sys.stderr,
            )
        # The CRM's own status word when it sent one (a 402 upstream arrives as a 502 carrying
        # `insufficient_credit`); read from `data.executionStatus` only, never quoted from the body.
        _report(_error_status(response))
        return None

    try:
        body: Any = response.json()
    except ValueError:
        _report("invalid_response")
        return None

    data = body.get("data") if isinstance(body, dict) else None
    if not isinstance(data, dict):
        _report("invalid_response")
        return None

    plan = data.get("strategy")
    if not isinstance(plan, dict):
        _report("invalid_response")
        return None

    _report(str(data.get("executionStatus") or "ok"))

    return DiscoveryStrategy(
        selectors=_clean(plan.get("selectors"), cap=MAX_SELECTORS),
        queries=_clean(plan.get("queries"), cap=MAX_QUERIES),
        place_types=_clean(plan.get("placeTypes"), cap=MAX_PLACE_TYPES),
        sub_areas=_clean(plan.get("subAreas"), cap=MAX_SUB_AREAS),
        status=str(data.get("executionStatus") or "ok"),
    )
