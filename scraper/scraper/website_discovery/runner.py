"""Runs candidate website discovery over the CRM's website-search queue.

    queue -> OSM tags (batched) -> Wikidata P856 -> domain probe (DNS)
          -> candidates -> fetch each -> CRM adjudicates

Tier 0 LOOKS UP what OSM already knows; Tier 1 SEARCHES for what it does not. Both run on every
lead, and both are reported as run even when they find nothing — the CRM refuses an exhaustion claim
that does not cover every required tier, so a partial run leaves the lead searchable rather than
letting it read as thoroughly searched.

Every CRM write is opt-in, exactly as ``discover`` and ``audit`` are: without ``--commit`` this reads
the queue, resolves candidates and prints what it found, and submits nothing.

IT NEVER CONCLUDES ANYTHING. It reports candidates and, when a tier produced none, claims exhaustion
— a claim the CRM independently refuses while any candidate is unchecked. Absence itself is only ever
concluded by a human reviewing the evidence.
"""

from __future__ import annotations

import sys
from typing import Any

import httpx

from scraper.config import Config
from scraper.safety import describe_target
from scraper.website_discovery.fetcher import fetch_candidate
from scraper.website_discovery.tier0 import (
    candidates_for,
    fetch_osm_elements,
    resolve_osm_elements_by_location,
    resolve_wikidata_websites,
)
from scraper.website_discovery.tier1 import TIER_PROBE
from scraper.website_discovery.tier1 import candidates_for as probe_candidates_for
from scraper.website_discovery.tier1 import hosts_for_leads, resolve_hosts


class CrmEvidenceClient:
    """The CRM endpoints for website evidence. The only channel this package writes through."""

    def __init__(self, *, base_url: str, token: str, user_agent: str, timeout: float = 60.0) -> None:
        self.base_url = base_url.rstrip("/")
        self._client = httpx.Client(
            timeout=timeout,
            headers={
                "Authorization": f"Bearer {token}",
                "Accept": "application/json",
                "User-Agent": user_agent,
            },
        )

    def queue(self, limit: int, lead_ids: list[str] | None = None) -> list[dict]:
        params: dict = {"limit": limit}
        if lead_ids is not None:
            params["lead_ids"] = ",".join(lead_ids)
        r = self._client.get(f"{self.base_url}/website-evidence/queue", params=params)
        r.raise_for_status()
        return r.json().get("data", [])

    def submit(self, lead_id: str, candidates: list[dict], tiers_run: list[str],
               exhausted: bool) -> dict:
        r = self._client.post(
            f"{self.base_url}/leads/{lead_id}/website-candidates",
            json={"candidates": candidates, "tiers_run": tiers_run, "exhausted": exhausted},
        )
        r.raise_for_status()
        return r.json().get("data", {})

    def verify(self, lead_id: str, candidate_id: str, findings: dict[str, Any]) -> dict:
        r = self._client.put(
            f"{self.base_url}/leads/{lead_id}/website-candidates/{candidate_id}", json=findings
        )
        r.raise_for_status()
        return r.json().get("data", {})

    def close(self) -> None:
        self._client.close()


def run_website_discovery(
    config: Config, limit: int, *, commit: bool = False, lead_ids: list[str] | None = None
) -> int:
    """Find and verify candidate websites for leads that have none."""
    if not config.crm_token:
        print("ERROR: CRM_TOKEN is not set.", file=sys.stderr)
        return 2

    client = CrmEvidenceClient(
        base_url=config.crm_base_url, token=config.crm_token, user_agent=config.user_agent
    )

    target = describe_target(config.crm_base_url)

    try:
        try:
            leads = client.queue(limit, lead_ids) if lead_ids is not None else client.queue(limit)
        except httpx.HTTPStatusError as exc:
            # Name the status: a 401 here almost always means the token belongs to another
            # deployment (they are per-database), which is otherwise a slow thing to work out.
            print(
                f"Could not read the website-search queue from {target.host}: "
                f"HTTP {exc.response.status_code}.",
                file=sys.stderr,
            )
            return 2
        except httpx.HTTPError as exc:
            print(
                f"Could not reach the CRM at {target.host} ({type(exc).__name__}).", file=sys.stderr
            )
            return 2

        if not leads:
            print("No leads awaiting a website search — nothing to do.", file=sys.stderr)
            return 0

        mode = "COMMIT" if commit else "DRY RUN"
        # State the target on every run, not just refused ones: a read pointed at the wrong
        # environment is how an operator discovers the setting is wrong, before a write does.
        print(
            f"[{mode}] CRM target: {target.label} — searching websites for {len(leads)} lead(s)...",
            file=sys.stderr,
        )

        # ONE Overpass query for the whole batch, and one Wikidata call per 40 brands. Both are
        # shared community infrastructure; per-lead requests would be an unreasonable ask.
        tags_by_place = fetch_osm_elements(
            [str(l.get("placeId") or "") for l in leads],
            overpass_url=config.overpass_url, user_agent=config.user_agent,
        )

        # THE SECOND WAY TO REACH AN ELEMENT. A Google lead's `places/ChIJ…` is not an OSM id, so the
        # lookup above cannot see it and Tier 0 had nowhere to look for the entire Google pool. Here
        # the lead's own coordinates and name are matched against real elements instead. A match
        # contributes the tags OSM genuinely holds, keyed by the lead's place_id so the assembly
        # below reads them exactly as it reads a directly-resolved element's. No match contributes
        # nothing, and that lead's Tier 0 stays honestly unrun.
        resolved = resolve_osm_elements_by_location(
            leads, overpass_url=config.overpass_url, user_agent=config.user_agent,
        )
        for place_id, element in resolved.items():
            tags_by_place.setdefault(place_id, element["tags"])
        resolved_by_place = {pid: e["osm_id"] for pid, e in resolved.items()}

        qids = {t.get("brand:wikidata") for t in tags_by_place.values() if t.get("brand:wikidata")}
        websites_by_qid = resolve_wikidata_websites(qids, user_agent=config.user_agent)

        results = candidates_for(leads, tags_by_place, websites_by_qid, resolved_by_place)

        tier0_found = sum(len(r.candidates) for r in results)
        tier0_ran = sum(1 for r in results if r.tiers_run)
        print(
            f"  tier 0: {tier0_found} candidate(s) across {len(results)} lead(s) "
            f"({len(qids)} brand(s) resolved); element available for {tier0_ran} lead(s), "
            f"{len(resolved)} matched by location.",
            file=sys.stderr,
        )

        # TIER 1 — deterministic domain probing. Runs for EVERY lead, including those Tier 0 already
        # answered: a second independent candidate is what gives the CRM's margin rule something to
        # weigh, and the tier must be recorded as run either way or an exhaustion claim would
        # understate the search. DNS for the whole batch in one pass; no HTTP happens here.
        hosts_by_lead = hosts_for_leads(leads)
        live_hosts = resolve_hosts({h for hosts in hosts_by_lead.values() for h in hosts})
        probes = probe_candidates_for(
            leads,
            hosts_by_lead,
            live_hosts,
            {r.lead_id: {c["url"] for c in r.candidates} for r in results},
        )

        for result in results:
            result.candidates.extend(probes.get(result.lead_id, []))
            # Appended unconditionally — "attempted and empty" is still attempted, exactly as Tier 0
            # reports itself for a lead whose place_id could not be looked up.
            result.tiers_run.append(TIER_PROBE)

        tier1_found = sum(len(p) for p in probes.values())
        found = tier0_found + tier1_found
        exhausted = sum(1 for r in results if r.exhausted)
        print(
            f"  tier 1: {tier1_found} candidate(s) from {len(live_hosts)} resolving host(s).",
            file=sys.stderr,
        )
        print(
            f"  total: {found} candidate(s) across {len(results)} lead(s); "
            f"{exhausted} lead(s) had none.",
            file=sys.stderr,
        )

        if not commit:
            for r in results:
                for c in r.candidates:
                    print(f"  {r.lead_id}  {c['source']:<9} {c['url']}  [{c['source_detail']}]")
            print(
                f"[{mode}] nothing submitted. Re-run with --commit to record this evidence.",
                file=sys.stderr,
            )
            return 0

        verified_total = 0
        for result in results:
            try:
                data = client.submit(
                    result.lead_id, result.candidates, result.tiers_run, result.exhausted
                )
            except httpx.HTTPError as exc:
                # One lead failing must not cost the rest of the batch its evidence.
                print(f"  lead {result.lead_id}: submit failed ({type(exc).__name__}).", file=sys.stderr)
                continue

            # Fetch and adjudicate anything still unchecked. The CRM decides; this only observes.
            for candidate in data.get("candidates", []):
                if candidate.get("checkedAt"):
                    continue
                findings = fetch_candidate(candidate["url"], user_agent=config.user_agent)
                try:
                    after = client.verify(result.lead_id, candidate["id"], findings)
                except httpx.HTTPError as exc:
                    print(f"  candidate {candidate['id']}: verify failed ({type(exc).__name__}).",
                          file=sys.stderr)
                    continue
                verified_total += sum(
                    1 for c in after.get("candidates", []) if c.get("verified") is True
                )

            # Re-declare exhaustion now that every candidate has been checked: the CRM refuses the
            # claim while anything is unchecked, so the first submit could not have set it.
            if result.candidates:
                try:
                    client.submit(result.lead_id, [], result.tiers_run, exhausted=True)
                except httpx.HTTPError:
                    pass

        print(
            f"[{mode}] done — {found} candidate(s) recorded, {verified_total} verified. "
            "Leads with no surviving candidate await human confirmation before any "
            "'no-website' conclusion.",
            file=sys.stderr,
        )
        return 0
    finally:
        client.close()
