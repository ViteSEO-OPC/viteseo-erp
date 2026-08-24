"""OpenAI-assisted discovery — Capability A.

    OpenAI proposes candidate business NAMES  ->  deterministic OSM resolution  ->  RawBusiness

WHAT THIS IS FOR. The OpenStreetMap provider finds businesses by tag, using 19 hand-listed
``CATEGORY_SELECTORS``. OSM holds businesses those selectors miss — a pharmacy mapped as
``shop=chemist``, a clinic under ``healthcare=*``, a shop whose only distinguishing tag is its name.
A language model is good at naming businesses of a kind in a place; it is not good at knowing whether
one exists. So it proposes and {@see OsmNameResolver} disposes.

WHAT THE MODEL IS NEVER ASKED FOR, AND NEVER TRUSTED WITH: ``provider_id``, coordinates, phone,
email, website, or category. Every field on every returned record comes from a real OSM element. The
model's entire influence is over WHICH names get looked up.

THE CEILING, STATED PLAINLY. A business absent from OSM cannot be resolved and is dropped. This
provider therefore widens coverage WITHIN OSM; it does not reach beyond it. Reaching genuinely
unmapped businesses is Capability B — a candidate verified by its own website or Wikidata rather than
by an OSM node — which is why ``RawBusiness`` already carries optional ``verification_source`` /
``verification_confidence`` fields this provider fills with "osm". Nothing here forecloses that.

FAILURE IS EMPTY, NEVER WRONG. Disabled, timed out, rate-limited, non-2xx, malformed JSON: every path
yields no proposals, which ``run_discovery`` already handles as "nothing discovered — skipping
ingest". A discovery run that finds nothing is a normal outcome; one that invents businesses is not.
"""

from __future__ import annotations

import json
import sys
from typing import Any, Optional

import httpx

from scraper.models import RawBusiness
from scraper.providers.base import DiscoveryProvider
from scraper.providers.openstreetmap import OpenStreetMapProvider
from scraper.providers.osm_resolver import OsmNameResolver

#: Proposals are asked for beyond `limit` because resolution drops some — a model that names 20 real
#: businesses may only have 12 mapped in OSM. Bounded hard: this multiplies MODEL tokens, never OSM
#: load (the resolver batches into one query regardless) and never what reaches the CRM (the caller's
#: `limit` still truncates).
PROPOSAL_OVERSAMPLE = 2
MAX_PROPOSALS = 60

SYSTEM_PROMPT = """\
You list real businesses that a mapping database is likely to contain for a given place and category.

Return business NAMES only. Do not return addresses, coordinates, phone numbers, websites, or ids —
those are looked up from an authoritative source, and anything you supplied would be discarded.

Rules:
- Name only businesses you have genuine reason to believe exist at that place.
- Prefer the name as it would appear on the storefront or signage.
- Include independent local businesses, not only well-known chains.
- Returning FEWER real businesses is better than padding the list with plausible inventions. Every
  name is checked against an authoritative database and unverifiable ones are discarded, so invented
  names cost accuracy and gain nothing.

Reply with JSON only: {"businesses": ["Name One", "Name Two"]}\
"""


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
        api_key: str,
        model: str,
        base_url: str,
        timeout: float,
        osm: OpenStreetMapProvider,
    ) -> None:
        if not api_key:
            # Loud, at construction. A silent fall back to OSM would quietly corrupt the very
            # comparison this provider exists to enable: the run would be labelled `openai` in the
            # metrics while actually being a plain OSM run.
            raise ValueError(
                "OPENAI_API_KEY is required for --provider openai. Set it, or use --provider osm."
            )
        self.api_key = api_key
        self.model = model
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self._osm = osm
        self._resolver = OsmNameResolver(lambda names, area: osm.find_by_name(names, area=area))
        self._client = httpx.Client(timeout=timeout)
        self._stats: dict[str, Any] = {}

    def discover(self, *, area: str, category: str, limit: int) -> list[RawBusiness]:
        ask = min(limit * PROPOSAL_OVERSAMPLE, MAX_PROPOSALS)
        proposals, usage = self._propose(area=area, category=category, count=ask)

        outcome = self._resolver.resolve(proposals, area=area)

        self._stats = {
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

    # -- the model -----------------------------------------------------------------------------

    def _propose(self, *, area: str, category: str, count: int) -> tuple[list[str], dict[str, Any]]:
        """Candidate names, and token usage. Any failure yields no proposals — never an exception."""
        try:
            response = self._client.post(
                f"{self.base_url}/chat/completions",
                headers={"Authorization": f"Bearer {self.api_key}"},
                json={
                    "model": self.model,
                    # Deterministic sampling so a repeated run over the same target is comparable.
                    "temperature": 0,
                    "response_format": {"type": "json_object"},
                    "messages": [
                        {"role": "system", "content": SYSTEM_PROMPT},
                        {
                            "role": "user",
                            "content": json.dumps(
                                {"area": area, "category": category, "max_results": count}
                            ),
                        },
                    ],
                },
            )
        except httpx.HTTPError as exc:
            print(f"OpenAI request failed ({type(exc).__name__}) — no proposals.", file=sys.stderr)
            return [], {}

        # 429 and every other non-2xx alike: no proposals, and the run reports nothing discovered.
        if response.status_code >= 400:
            print(
                f"OpenAI returned HTTP {response.status_code} — no proposals.", file=sys.stderr
            )
            return [], {}

        try:
            payload = response.json()
            content = payload["choices"][0]["message"]["content"]
            usage = payload.get("usage") or {}
        except (ValueError, KeyError, IndexError, TypeError):
            print("OpenAI response was not in the expected shape — no proposals.", file=sys.stderr)
            return [], {}

        names = self._parse_names(content)
        return names, {
            "model": self.model,
            "prompt_tokens": int(usage.get("prompt_tokens") or 0),
            "completion_tokens": int(usage.get("completion_tokens") or 0),
        }

    @staticmethod
    def _parse_names(content: str) -> list[str]:
        """Names out of the model's JSON, or an empty list. Never raises on bad output."""
        try:
            data = json.loads(content)
        except (ValueError, TypeError):
            return []
        if not isinstance(data, dict):
            return []

        raw = data.get("businesses")
        if not isinstance(raw, list):
            return []

        # Take only usable strings. A model that returns objects, nulls, or numbers among the names
        # loses those entries rather than the whole batch.
        return [item.strip() for item in raw if isinstance(item, str) and item.strip()]

    def close(self) -> None:
        self._client.close()
        self._osm.close()
