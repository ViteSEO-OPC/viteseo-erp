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

import math
import re
import sys
import time
import unicodedata
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

    elements = _overpass(f"[out:json][timeout:60];({''.join(parts)});out center;",
                         overpass_url, user_agent, timeout)
    return {f"{e.get('type')}/{e.get('id')}": (e.get("tags") or {}) for e in elements}


def _overpass(query: str, overpass_url: str, user_agent: str, timeout: float) -> list[dict]:
    """POST one Overpass query and return its elements, or [] if it could not be answered.

    Shared by the id lookup above and the location resolver below so there is ONE definition of how
    this codebase talks to Overpass — same retry budget, same backoff, same failure-is-empty rule.
    An unanswered query yields no elements, which downstream is indistinguishable from "this tier
    could not look" and therefore never counts as a tier that ran.
    """
    for attempt in range(4):
        try:
            response = httpx.post(overpass_url, content=query, timeout=timeout,
                                  headers={"Content-Type": "text/plain", "User-Agent": user_agent})
        except httpx.HTTPError as exc:
            print(f"Overpass unreachable ({type(exc).__name__}).", file=sys.stderr)
            return []
        if response.status_code == 200:
            try:
                return list(response.json().get("elements", []))
            except ValueError:
                return []
        # 429/504 are ordinary under load; back off rather than giving up on the batch.
        time.sleep(6 * (attempt + 1))

    print("Overpass did not answer after retries.", file=sys.stderr)
    return []


# ── LOCATION RESOLUTION ────────────────────────────────────────────────────────────────────────────
#
# WHY THIS EXISTS. Tier 0 reads an OSM element, and it finds that element by id. A lead discovered
# through Google Places carries `places/ChIJ…`, which is not an OSM id and cannot be turned into one:
# the two projects have no shared identifier. So for every Google lead both Tier 0 tiers had nowhere
# to look, `tiers_run` was honestly empty, `REQUIRED_TIERS` could never be satisfied, and the lead
# could never reach a genuine exhaustion — no matter how absent its website actually was.
#
# The fix is not to relax the tier-honesty rule. It is to give the tier somewhere to look. A business
# is a PLACE, and both projects record where it is and what it is called, so a Google lead can be
# matched to a real OSM element by its own factual coordinates and name.
#
# WHAT THIS WILL NOT DO. It will not invent an element. `MATCH_RADIUS_M` and `MATCH_MIN_SCORE` are
# both bars, not preferences: no element inside the radius whose name genuinely corroborates the
# lead's means NO MATCH, the lead is reported with Tier 0 unrun exactly as before, and it stays
# un-exhausted. A resolved element contributes only the tags OSM really holds — this reads, it never
# writes, and nothing here produces a URL that OSM did not already publish.

#: How far a Google coordinate may sit from the OSM element that is the same business. Google and
#: OSM place the same shopfront differently — rooftop centroid vs. entrance node — and ~120 m covers
#: that disagreement without reaching the next business along a commercial strip.
MATCH_RADIUS_M = 120.0

#: How much of the lead's distinctive name the element must account for. Coordinates alone are not
#: identity: at 120 m in a Philippine commercial district there are many businesses, so the name is
#: what decides WHICH of them this is.
MATCH_MIN_SCORE = 0.6

#: Leads per Overpass query. Each contributes one `around` clause; Overpass is shared community
#: infrastructure and a query is cheaper than a round trip, but an unbounded one is neither.
_LOCATION_BATCH = 25

#: Words that identify a company's FORM, not the company. Two unrelated businesses both being
#: "Inc" is not evidence they are the same one, and leaving these in would let a legal suffix carry
#: a match on its own.
_GENERIC_TOKENS = frozenset({
    "inc", "incorporated", "corp", "corporation", "company", "ltd", "limited", "llc", "plc",
    "group", "holdings", "enterprise", "enterprises", "and", "sons", "the", "for", "philippines",
    "phil", "branch", "store", "shop", "outlet", "main",
})


def normalize_name(name: str) -> str:
    """Casefolded ASCII with punctuation flattened to spaces. Pure."""
    decomposed = unicodedata.normalize("NFKD", name or "")
    ascii_only = decomposed.encode("ascii", "ignore").decode("ascii")
    return re.sub(r"[^a-z0-9]+", " ", ascii_only.lower()).strip()


def name_tokens(name: str) -> set[str]:
    """The DISTINCTIVE words of a business name — what is left once form and filler are removed."""
    return {
        token for token in normalize_name(name).split()
        if len(token) >= 3 and token not in _GENERIC_TOKENS
    }


def name_match_score(lead_name: str, element_name: str) -> float:
    """How much of the LEAD's distinctive name the element's name accounts for, 0.0-1.0. Pure.

    Deliberately asymmetric. An OSM element is routinely more specific than the Google record —
    "Jollibee" against "Jollibee SM North EDSA" is the same restaurant — so requiring the element to
    add nothing would refuse correct matches. The lead's own words are what must be accounted for.
    Either name having no distinctive words at all scores 0: there is nothing to corroborate.
    """
    lead = name_tokens(lead_name)
    element = name_tokens(element_name)
    if not lead or not element:
        return 0.0
    return len(lead & element) / len(lead)


def haversine_m(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Great-circle distance in metres. Pure."""
    radius = 6_371_000.0
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dp = math.radians(lat2 - lat1)
    dl = math.radians(lon2 - lon1)
    a = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * radius * math.asin(math.sqrt(a))


def element_position(element: dict) -> Optional[tuple[float, float]]:
    """A node's own position, or a way/relation's centre. None when Overpass returned neither."""
    if element.get("lat") is not None and element.get("lon") is not None:
        return float(element["lat"]), float(element["lon"])
    center = element.get("center") or {}
    if center.get("lat") is not None and center.get("lon") is not None:
        return float(center["lat"]), float(center["lon"])
    return None


def match_elements_to_leads(leads: list[dict], elements: list[dict],
                            radius_m: float = MATCH_RADIUS_M,
                            min_score: float = MATCH_MIN_SCORE) -> dict[str, dict]:
    """Assign at most one OSM element to each lead. Pure — no network, so the rule is testable.

    ONE ELEMENT, ONE LEAD. Two different businesses cannot be the same OSM element, so assignment is
    greedy over every candidate pair ranked by name score then proximity, and both sides are consumed
    when a pair is taken. Without that, two same-named branches 100 m apart would both resolve to
    whichever one OSM happens to map, and each would be credited with the other's evidence.

    Returns ``place_id -> {"osm_id", "tags", "distance_m", "score"}``, containing ONLY leads that
    genuinely matched. A lead absent from the result had no element to read, which is the honest
    input to "this tier could not run".
    """
    pairs: list[tuple[float, float, str, str, dict]] = []

    for lead in leads:
        place_id = str(lead.get("placeId") or "")
        lat, lon = lead.get("latitude"), lead.get("longitude")
        if not place_id or lat is None or lon is None:
            continue
        for element in elements:
            position = element_position(element)
            element_id = f"{element.get('type')}/{element.get('id')}"
            if position is None or not element.get("id"):
                continue
            distance = haversine_m(float(lat), float(lon), position[0], position[1])
            if distance > radius_m:
                continue
            score = name_match_score(
                str(lead.get("business") or ""), str((element.get("tags") or {}).get("name") or "")
            )
            if score < min_score:
                continue
            pairs.append((score, distance, place_id, element_id, element))

    # Best name evidence first; proximity only breaks ties between equally-named candidates.
    pairs.sort(key=lambda p: (-p[0], p[1]))

    resolved: dict[str, dict] = {}
    taken_elements: set[str] = set()
    for score, distance, place_id, element_id, element in pairs:
        if place_id in resolved or element_id in taken_elements:
            continue
        taken_elements.add(element_id)
        resolved[place_id] = {
            "osm_id": element_id,
            "tags": dict(element.get("tags") or {}),
            "distance_m": round(distance, 1),
            "score": round(score, 2),
        }

    return resolved


def resolve_osm_elements_by_location(leads: list[dict], overpass_url: str, user_agent: str,
                                     radius_m: float = MATCH_RADIUS_M,
                                     min_score: float = MATCH_MIN_SCORE,
                                     timeout: float = 90.0) -> dict[str, dict]:
    """Find the real OSM element for leads whose ``place_id`` is not an OSM id. Read-only.

    Only leads that need it are asked about: a lead whose id already parses is resolved by
    {@see fetch_osm_elements} and is not sent here. Everything else — no coordinates, no name, no
    element within the radius, no name corroboration — simply yields no entry.
    """
    pending = [
        lead for lead in leads
        if parse_place_id(str(lead.get("placeId") or "")) is None
        and lead.get("latitude") is not None
        and lead.get("longitude") is not None
        and name_tokens(str(lead.get("business") or ""))
    ]
    if not pending:
        return {}

    resolved: dict[str, dict] = {}
    for start in range(0, len(pending), _LOCATION_BATCH):
        batch = pending[start:start + _LOCATION_BATCH]
        clauses = "".join(
            f'nwr(around:{radius_m:.0f},{float(l["latitude"]):.6f},{float(l["longitude"]):.6f})["name"];'
            for l in batch
        )
        elements = _overpass(f"[out:json][timeout:60];({clauses});out center tags;",
                             overpass_url, user_agent, timeout)
        resolved.update(match_elements_to_leads(batch, elements, radius_m, min_score))
        time.sleep(1.0)

    return resolved


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
                   websites_by_qid: dict[str, str],
                   resolved_by_place: Optional[dict[str, str]] = None) -> list[LeadCandidates]:
    """Assemble each lead's candidate list from the tags and the resolved brand websites.

    Pure — no network — so the assembly rules stay testable without Overpass or Wikidata.

    A TIER IS ONLY "RUN" WHERE IT COULD ACTUALLY LOOK. Both tiers here resolve through an OSM element,
    so the question is whether this lead HAS one. There are two ways it can, and ``resolved_by_place``
    is the second:

    * its ``place_id`` is an OSM id and parses (the OpenStreetMap discovery path), or
    * it was matched to a real element by coordinates and name
      ({@see resolve_osm_elements_by_location}) — the Google Places path, whose ``places/ChIJ…`` ids
      are not OSM ids and never will be.

    Neither means the lead HAS a website; both mean there was finally somewhere to look. A lead in
    neither case still reports ``tiers_run=[]``, because reporting those as run would record "two
    independent tiers found nothing" where two independent tiers were never able to look — and the
    CRM builds its exhaustion claim on exactly that list. Measured on the live pool, claiming them
    would have written a High-priority "this business has no website" for hundreds of Google leads on
    the strength of one DNS probe.

    ATTEMPTED-AND-EMPTY IS STILL RUN. Where an element WAS resolved, both tiers are reported whether
    or not they found anything — an element with no ``website`` tag is a real negative answer, and
    claiming the tier was skipped would understate that search. The distinction is between a lookup
    that returned nothing and a lookup that was never possible.
    """
    resolved_by_place = resolved_by_place or {}
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

        place_id = str(lead.get("placeId") or "")
        # Provenance, so a candidate found through a MATCHED element says which element that was and
        # a reviewer can check the match rather than trust it.
        via = resolved_by_place.get(place_id)
        suffix = f" via {via}" if via else ""

        # Tier 0a — a URL sitting on the element that discovery did not carry across.
        for key in ("website", "contact:website", "url"):
            if tags.get(key):
                add(str(tags[key]), TIER_OSM_TAG, f"{key}{suffix}")

        # Tier 0b — the brand's official website, via the curated QID.
        qid = tags.get("brand:wikidata")
        if qid and websites_by_qid.get(qid):
            brand = tags.get("brand") or tags.get("official_name") or qid
            add(websites_by_qid[qid], TIER_WIKIDATA, f"{qid} ({brand}){suffix}")

        # Both tiers read through the same OSM element, so both are reachable exactly when one was
        # found — by id, or by the coordinate-and-name match that stands in for an id Google cannot
        # give us. No element, no tiers: that is the whole of the honesty rule.
        lookup_possible = parse_place_id(place_id) is not None or place_id in resolved_by_place
        results.append(LeadCandidates(
            lead_id=str(lead["id"]),
            candidates=found,
            tiers_run=[TIER_OSM_TAG, TIER_WIKIDATA] if lookup_possible else [],
        ))

    return results
