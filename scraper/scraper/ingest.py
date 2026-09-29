"""CRM ingest client.

The scraper's channel into the CRM for discovery: the programmatic enrichment ingest endpoint
(``POST /leads/discovered``, whose ``dry_run`` flag is set from ``--commit``) plus the read-only
Website Intelligence queue (``GET``). It submits normalized fit-data and reads back a summary. It
never touches the CRM database, never calls other CRM endpoints, and never runs CRM business logic.

This client performs NO website-enrichment write. That mutation lives in exactly one place —
``website_intelligence.pipeline.SubmitAuditPipeline`` — where it is gated on an explicit ``--commit``.
"""

from __future__ import annotations

import httpx

from scraper.models import NormalizedBusiness, ProvenanceOnlyRecord


class CrmIngestClient:
    def __init__(self, *, base_url: str, token: str, user_agent: str, timeout: float = 30.0) -> None:
        self.base_url = base_url.rstrip("/")
        self._client = httpx.Client(
            timeout=timeout,
            headers={
                "Accept": "application/json",
                "Content-Type": "application/json",
                "User-Agent": user_agent,
                "Authorization": f"Bearer {token}",
            },
        )

    def ingest(
        self,
        businesses: list[NormalizedBusiness],
        *,
        dry_run: bool,
        provenance_only: list[ProvenanceOnlyRecord] | None = None,
    ) -> dict:
        """Submit a batch to the CRM. ``dry_run=True`` validates + reports without persisting.

        ``provenance_only`` travels only when there is some, so every ordinary request is exactly the
        request it was before the field existed. A CRM that predates the field ignores it.
        """
        payload: dict = {
            "dry_run": dry_run,
            "businesses": [b.to_payload() for b in businesses],
        }
        if provenance_only:
            payload["provenance_only"] = [p.to_payload() for p in provenance_only]
        resp = self._client.post(f"{self.base_url}/leads/discovered", json=payload)
        resp.raise_for_status()
        return resp.json().get("data", {})

    # -- Website Intelligence --------------------------------------------------------------------

    def fetch_enrichment_queue(self, limit: int, lead_ids: list[str] | None = None) -> list[dict]:
        """Businesses with a website URL to audit (never-audited first) — only ``lead_ids`` when given."""
        params: dict = {"limit": limit}
        if lead_ids is not None:
            params["lead_ids"] = ",".join(lead_ids)
        resp = self._client.get(f"{self.base_url}/website-enrichment/queue", params=params)
        resp.raise_for_status()
        return resp.json().get("data", [])

    # NOTE: audit findings are NOT submitted from this client. The single write path is
    # SubmitAuditPipeline, which is gated on an explicit --commit. A convenience method here would
    # be a second, ungated route to the same mutation — so it is deliberately absent.

    def close(self) -> None:
        self._client.close()
