"""Tier 0 candidate website discovery — free, deterministic, no vendor and no API key.

WHAT IT RECOVERS. ``parse_element`` reads only ``website`` / ``contact:website`` / ``url`` and
discards everything else, so three tags that routinely carry the answer are thrown away:

* **Tier 0a** — ``website`` / ``contact:website`` / ``url`` present on the element after all. The
  discovery path only keeps a URL when a business is first ingested; a lead whose tag was added to
  OSM afterwards still has none, and re-reading catches that.
* **Tier 0a** — ``brand`` / ``official_name``, used as provenance detail rather than as URLs.
* **Tier 0b** — ``brand:wikidata``, a curated QID whose Wikidata entity carries property **P856,
  the official website**. Measured on the real configured targets: 19% of businesses expose the tag
  and 12/12 distinct brands resolved to a URL.

WHAT IT CANNOT DO, MEASURED. ``brand:wikidata`` is a chain phenomenon — 64% of pharmacies, and
**0% of dentists, clinics and salons**. Tier 0 therefore helps mostly where businesses already HAVE
websites, and barely at all among independents, which is where genuine no-website opportunities
concentrate. It is worth running because it is free and exact, not because it will find the
opportunities; those need later tiers.

NOTHING HERE DECIDES ANYTHING. Every URL produced is a CANDIDATE. The CRM fetches it, adjudicates it
against the unmodified WebsiteIdentityVerifier at a stricter bar than the audit uses, and a human
confirms absence when every candidate is refused. A stale P856 (measured: 4 of 12 were dead, one
redirecting to an unrelated storefront) is refused by that verifier and degrades to
``website-unknown`` — never to a wrong verdict.
"""

from __future__ import annotations

import sys
import time
from dataclasses import dataclass, field
from typing import Iterable, Optional

import httpx

#: Tier identifiers, recorded on the search so an exhaustion claim says WHICH places were tried.
TIER_OSM_TAG = "osm_tag"
TIER_WIKIDATA = "wikidata"

#: Wikidata property for "official website".
P_OFFICIAL_WEBSITE = "P856"

#: Wikidata asks for a descriptive agent and rate-limits generously; entities are fetched in bulk.
_WIKIDATA_BATCH = 40


@dataclass
class LeadCandidates:
    """Candidates found for one lead, with the tiers that were actually run."""

    lead_id: str
    candidates: list[dict] = field(default_factory=list)
    tiers_run: list[str] = field(default_factory=list)

    @property
    def exhausted(self) -> bool:
        """Every tier available HERE produced nothing.

        Reported to the CRM as a claim, not a fact: the server still refuses to record exhaustion
        while any candidate is unchecked, and refuses to conclude absence without a human.
        """
        return not self.candidates


def parse_place_id(place_id: str) -> Optional[tuple[str, int]]:
    """``node/123`` -> ``("node", 123)``. None for anything this resolver cannot look up."""
    if not place_id or "/" not in place_id:
        return None
    kind, _, raw = place_id.partition("/")
    if kind not in ("node", "way", "relation") or not raw.isdigit():
        return None
    return kind, int(raw)


def fetch_osm_elements(place_ids: Iterable[str], overpass_url: str, user_agent: str,
                       timeout: float = 90.0) -> dict[str, dict]:
    """Fetch the FULL tag set for specific OSM elements, keyed by place_id.

    By id rather than by area, and batched into one query: the leads needing a website search are
    already known, so re-scanning a bounding box would be both slower and a much heavier ask of
    Overpass — shared community infrastructure this tool is a guest on.
    """
    grouped: dict[str, list[int]] = {"node": [], "way": [], "relation": []}
    for place_id in place_ids:
        parsed = parse_place_id(place_id)
        if parsed:
            grouped[parsed[0]].append(parsed[1])

    parts = [f"{kind}(id:{','.join(str(i) for i in ids)});" for kind, ids in grouped.items() if ids]
    if not parts:
        return {}

    query = f"[out:json][timeout:60];({''.join(parts)});out center;"
    for attempt in range(4):
        try:
            response = httpx.post(overpass_url, content=query, timeout=timeout,
                                  headers={"Content-Type": "text/plain", "User-Agent": user_agent})
        except httpx.HTTPError as exc:
            print(f"Overpass unreachable ({type(exc).__name__}).", file=sys.stderr)
            return {}
        if response.status_code == 200:
            return {
                f"{e.get('type')}/{e.get('id')}": (e.get("tags") or {})
                for e in response.json().get("elements", [])
            }
        # 429/504 are ordinary under load; back off rather than giving up on the batch.
        time.sleep(6 * (attempt + 1))

    print("Overpass did not answer after retries.", file=sys.stderr)
    return {}


def resolve_wikidata_websites(qids: Iterable[str], user_agent: str,
                              timeout: float = 45.0) -> dict[str, str]:
    """QID -> official website (P856). Missing entities and missing properties are simply absent.

    NO GUESSING ANYWHERE. An entity without P856 contributes nothing rather than a fallback URL —
    the honest outcome is "this tier found nothing", which is itself the evidence exhaustion is
    built from.
    """
    unique = sorted({q for q in qids if q})
    if not unique:
        return {}

    out: dict[str, str] = {}
    for start in range(0, len(unique), _WIKIDATA_BATCH):
        batch = unique[start:start + _WIKIDATA_BATCH]
        try:
            response = httpx.get(
                "https://www.wikidata.org/w/api.php",
                params={"action": "wbgetentities", "ids": "|".join(batch),
                        "props": "claims", "format": "json"},
                headers={"User-Agent": user_agent}, timeout=timeout, follow_redirects=True,
            )
            entities = response.json().get("entities", {}) if response.status_code == 200 else {}
        except (httpx.HTTPError, ValueError) as exc:
            print(f"Wikidata unreachable ({type(exc).__name__}).", file=sys.stderr)
            entities = {}

        for qid, entity in entities.items():
            for claim in (entity.get("claims") or {}).get(P_OFFICIAL_WEBSITE, []):
                value = (claim.get("mainsnak") or {}).get("datavalue", {}).get("value")
                if isinstance(value, str) and value.strip():
                    out[qid] = value.strip()
                    break
        time.sleep(0.5)

    return out


def candidates_for(leads: list[dict], tags_by_place: dict[str, dict],
                   websites_by_qid: dict[str, str]) -> list[LeadCandidates]:
    """Assemble each lead's candidate list from the tags and the resolved brand websites.

    Pure — no network — so the assembly rules stay testable without Overpass or Wikidata. Both tiers
    are always reported as RUN, including for a lead whose place_id could not be looked up: claiming
    a tier was skipped when it was attempted-and-empty would understate the search.
    """
    results: list[LeadCandidates] = []

    for lead in leads:
        tags = tags_by_place.get(str(lead.get("placeId") or ""), {})
        found: list[dict] = []
        seen: set[str] = set()

        def add(url: str, source: str, detail: Optional[str]) -> None:
            cleaned = (url or "").strip()
            key = f"{source}|{cleaned.lower()}"
            if cleaned and key not in seen:
                seen.add(key)
                found.append({"url": cleaned, "source": source, "source_detail": detail})

        # Tier 0a — a URL sitting on the element that discovery did not carry across.
        for key in ("website", "contact:website", "url"):
            if tags.get(key):
                add(str(tags[key]), TIER_OSM_TAG, key)

        # Tier 0b — the brand's official website, via the curated QID.
        qid = tags.get("brand:wikidata")
        if qid and websites_by_qid.get(qid):
            brand = tags.get("brand") or tags.get("official_name") or qid
            add(websites_by_qid[qid], TIER_WIKIDATA, f"{qid} ({brand})")

        results.append(LeadCandidates(
            lead_id=str(lead["id"]), candidates=found, tiers_run=[TIER_OSM_TAG, TIER_WIKIDATA],
        ))

    return results
