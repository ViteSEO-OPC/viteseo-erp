"""Scrapy item pipeline — submits each audit to the CRM's Website Intelligence endpoint.

Provider-independent: it only needs a lead id + audit findings. The CRM derives `website_status`.

WRITES ARE OPT-IN. This pipeline is the ONLY place the audit mutates the CRM, and it does so only
when ``commit=True`` (from the ``CRM_COMMIT`` crawler setting, set by ``--commit``). Without it the
crawl still runs and every finding is reported, but not one PUT is issued — matching ``discover``,
where persistence has always required an explicit ``--commit``.
"""

from __future__ import annotations

import httpx


class SubmitAuditPipeline:
    @classmethod
    def from_crawler(cls, crawler):
        s = crawler.settings
        return cls(
            base_url=s.get("CRM_BASE_URL"),
            token=s.get("CRM_TOKEN"),
            user_agent=s.get("CRM_USER_AGENT", "CRM-Enterprise-Scraper/1.0"),
            # Default False: a missing/unset setting must never be read as permission to write.
            commit=bool(s.get("CRM_COMMIT", False)),
        )

    def __init__(self, base_url: str, token: str, user_agent: str, commit: bool = False) -> None:
        self.base_url = base_url.rstrip("/")
        self.commit = commit
        self._client = httpx.Client(
            timeout=30.0,
            headers={
                "Accept": "application/json",
                "Content-Type": "application/json",
                "User-Agent": user_agent,
                "Authorization": f"Bearer {token}",
            },
        )
        self.submitted = 0
        self.skipped = 0

    def process_item(self, item, spider):
        payload = dict(item)
        lead_id = payload.pop("lead_id")

        if not self.commit:
            # Dry run: the audit was performed and is reported, but nothing is sent to the CRM.
            # Returning before the request is what guarantees zero mutations — there is no
            # write path below this branch that a misconfiguration could fall through to.
            self.skipped += 1
            spider.logger.warning(
                "[DRY RUN] audited lead %s — not submitted (use --commit to write)", lead_id
            )
            return item

        resp = self._client.put(f"{self.base_url}/leads/{lead_id}/website-enrichment", json=payload)
        resp.raise_for_status()
        self.submitted += 1
        status = resp.json().get("websiteStatus")
        spider.logger.warning("audited lead %s → website_status=%s", lead_id, status)
        return item

    def close_spider(self, spider):
        self._client.close()
