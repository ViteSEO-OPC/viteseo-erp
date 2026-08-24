"""AI-assisted discovery — Capability A.

    CRM (holds the key, calls OpenAI)  ->  candidate NAMES  ->  deterministic OSM resolution  ->  RawBusiness

WHAT THIS IS FOR. The OpenStreetMap provider finds businesses by tag, using 19 hand-listed
``CATEGORY_SELECTORS``. OSM holds businesses those selectors miss — a pharmacy mapped as
``shop=chemist``, a clinic under ``healthcare=*``, a shop whose only distinguishing tag is its name.
A language model is good at naming businesses of a kind in a place; it is not good at knowing whether
one exists. So it proposes and {@see OsmNameResolver} disposes.

THE CREDENTIAL IS NOT HERE, AND CANNOT BE. This provider does not hold an OpenAI key and has no way
to obtain one: it asks the CRM for names over the same authenticated channel it already uses to ingest
leads, and the CRM makes the model call. So a worker process never receives, stores or logs the
credential — the only copy lives encrypted in the CRM, configured by an admin in
Settings -> Integrations -> Nearby Lead Generation.

WHAT THE MODEL IS NEVER ASKED FOR, AND NEVER TRUSTED WITH: ``provider_id``, coordinates, phone,
email, website, or category. Every field on every returned record comes from a real OSM element. The
model's entire influence is over WHICH names get looked up.

THE CEILING, STATED PLAINLY. A business absent from OSM cannot be resolved and is dropped. This
provider therefore widens coverage WITHIN OSM; it does not reach beyond it. Reaching genuinely
unmapped businesses is Capability B — a candidate verified by its own website or Wikidata rather than
by an OSM node — which is why ``RawBusiness`` already carries optional ``verification_source`` /
``verification_confidence`` fields this provider fills with "osm". Nothing here forecloses that.

FAILURE IS EMPTY, NEVER WRONG. Disabled, timed out, rate-limited, non-2xx, malformed JSON, or a CRM
that refuses because AI is not the effective provider: every path yields no proposals, which
``run_discovery`` already handles as "nothing discovered — skipping ingest". A discovery run that
finds nothing is a normal outcome; one that invents businesses is not.
"""

from __future__ import annotations

import sys
from typing import Any, Optional

import httpx

from scraper.models import RawBusiness
from scraper.providers.base import DiscoveryProvider
from scraper.providers.openstreetmap import OpenStreetMapProvider
from scraper.providers.osm_resolver import OsmNameResolver

#: Proposals are asked for beyond `limit` because resolution drops some — a model that names 20 real
#: businesses may only have 12 mapped in OSM. Bounded hard: this multiplies the CRM's MODEL spend,
#: never OSM load (the resolver batches into one query regardless) and never what reaches the CRM as
#: leads (the caller's `limit` still truncates). The CRM enforces its own ceiling as well.
PROPOSAL_OVERSAMPLE = 2
MAX_PROPOSALS = 60

#: Execution outcomes. `ok` is the ONLY one that means the candidate list is a real answer; every
#: other value means the run did not successfully consult the provider, and must never be presented
#: as a discovery that simply found nothing.
EXECUTION_OK = "ok"
#: The CRM could not be reached at all (network, timeout, unreadable response).
EXECUTION_CRM_UNREACHABLE = "crm_unreachable"
#: The CRM refused because AI is not the effective provider (409) — a configuration state, not an error.
EXECUTION_NOT_EFFECTIVE = "not_effective_provider"
#: A response we could not interpret. A failure, not an empty result.
EXECUTION_INVALID_RESPONSE = "invalid_response"



class OpenAiDiscoveryProvider(DiscoveryProvider):
    """Proposes candidates with a language model; resolves them deterministically against OSM.

    ``name`` is the provenance stamped onto ``leads.source``, so the two providers form clean cohorts
    for comparison. Note the records themselves carry OSM identity — the source records WHO FOUND the
    lead, which is exactly the question the Python-vs-AI comparison asks.
    """

    name = "openai"

    def __init__(
        self,
        *,
        crm_base_url: str,
        crm_token: str,
        timeout: float,
        osm: OpenStreetMapProvider,
    ) -> None:
        if not crm_token:
            # Loud, at construction. A silent fall back to OSM would quietly corrupt the very
            # comparison this provider exists to enable: the run would be labelled `openai` in the
            # metrics while actually being a plain OSM run. Note it is the CRM token that is
            # required now, not an OpenAI key — this process never has one.
            raise ValueError(
                "CRM_TOKEN is required for --provider openai (the CRM holds the OpenAI credential "
                "and makes the call). Set it, or use --provider osm."
            )
        self.crm_base_url = crm_base_url.rstrip("/")
        self.crm_token = crm_token
        self.timeout = timeout
        self._osm = osm
        self._resolver = OsmNameResolver(lambda names, area: osm.find_by_name(names, area=area))
        self._client = httpx.Client(timeout=timeout)
        self._stats: dict[str, Any] = {}

    def discover(self, *, area: str, category: str, limit: int) -> list[RawBusiness]:
        ask = min(limit * PROPOSAL_OVERSAMPLE, MAX_PROPOSALS)
        proposals, usage, status, error = self._propose(area=area, category=category, count=ask)

        # A FAILED CALL IS NOT AN EMPTY RESULT. Resolving nothing and reporting a clean run would let
        # a rejected credential look exactly like a town with no pharmacies — indefinitely. The status
        # travels into `stats()` and the CLI turns a non-OK status into a failed run.
        if status != EXECUTION_OK:
            self._stats = {
                "execution_status": status,
                "error_reason": error,
                "proposed": 0,
                "resolved": 0,
                "resolution_rate": 0.0,
                "unmatched": 0,
                "ambiguous": 0,
            }
            print(
                f"  {self.name}: provider execution FAILED ({status}"
                + (f": {error}" if error else "")
                + ") — no candidates, and this run is not a success.",
                file=sys.stderr,
            )
            return []

        outcome = self._resolver.resolve(proposals, area=area)

        self._stats = {
            "execution_status": EXECUTION_OK,
            "error_reason": None,
            "proposed": outcome.proposed_count,
            "resolved": len(outcome.resolved),
            "resolution_rate": outcome.resolution_rate,
            "unmatched": len(outcome.unmatched),
            "ambiguous": len(outcome.ambiguous),
            **usage,
        }

        if outcome.unmatched or outcome.ambiguous:
            print(
                f"  resolver: {len(outcome.resolved)}/{outcome.proposed_count} proposals confirmed "
                f"({len(outcome.unmatched)} not in OSM, {len(outcome.ambiguous)} ambiguous — all dropped).",
                file=sys.stderr,
            )

        # The caller's limit still binds: oversampling widens the SEARCH, never the import.
        return outcome.resolved[:limit]

    def stats(self) -> dict[str, Any]:
        """Per-run numbers for the metrics record. Read optionally by the CLI, like ``close()``."""
        return dict(self._stats)

    # -- the CRM (which holds the credential and calls the model) ------------------------------

    def _propose(
        self, *, area: str, category: str, count: int
    ) -> tuple[list[str], dict[str, Any], str, Optional[str]]:
        """Candidate names, token usage, EXECUTION STATUS and an error code, from the CRM.

        Never raises — a scheduled sweep must not abort because a model was slow. But it never hides a
        failure either: the status is what distinguishes "the model found nothing" from "the request
        never succeeded", and only ``EXECUTION_OK`` means the name list is a real answer.
        """
        try:
            response = self._client.post(
                f"{self.crm_base_url}/discovery/proposals",
                headers={
                    "Authorization": f"Bearer {self.crm_token}",
                    "Accept": "application/json",
                },
                json={"area": area, "category": category, "count": count},
            )
        except httpx.HTTPError as exc:
            return [], {}, EXECUTION_CRM_UNREACHABLE, type(exc).__name__

        # The CRM reports the upstream verdict in the body even on an error status, so read it first —
        # `auth_failed` from OpenAI is far more useful to an operator than "HTTP 502".
        payload: dict[str, Any] = {}
        try:
            body = response.json()
            if isinstance(body, dict) and isinstance(body.get("data"), dict):
                payload = body["data"]
        except ValueError:
            payload = {}

        if response.status_code >= 400:
            # 409 = the CRM refusing because AI is not the effective provider (the same guard the
            # settings page shows, enforced server-side). 502 = the CRM reached us fine but its
            # upstream provider failed. Either way this is NOT a successful empty discovery.
            status = str(payload.get("executionStatus") or "")
            if not status:
                status = (
                    EXECUTION_NOT_EFFECTIVE
                    if response.status_code == 409
                    else EXECUTION_CRM_UNREACHABLE
                )
            error = payload.get("errorReason") or f"http_{response.status_code}"
            return [], {}, status, str(error)

        status = str(payload.get("executionStatus") or EXECUTION_OK)
        if status != EXECUTION_OK:
            return [], {}, status, str(payload.get("errorReason") or "")

        raw = payload.get("proposals")
        if not isinstance(raw, list):
            return [], {}, EXECUTION_INVALID_RESPONSE, "missing_proposals"

        names = [n.strip() for n in raw if isinstance(n, str) and n.strip()]
        usage = payload.get("usage")

        return names, dict(usage) if isinstance(usage, dict) else {}, EXECUTION_OK, None

    def close(self) -> None:
        self._client.close()
        self._osm.close()
