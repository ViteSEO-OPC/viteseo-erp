"""Runs the Website Intelligence audit over the CRM queue.

Fetches the businesses that have a website URL, crawls each with Scrapy, and submits the audit
findings. Completely independent of the discovery provider — its only input is "leads that have a
website URL".
"""

from __future__ import annotations

import sys

from scrapy.crawler import CrawlerProcess

from scraper.config import Config
from scraper.ingest import CrmIngestClient
from scraper.website_intelligence.spider import WebsiteAuditSpider


def run_audit(config: Config, limit: int, *, commit: bool = False) -> int:
    """Audit the queued websites.

    ``commit`` defaults to False — reading the queue and crawling are safe, but submitting findings
    back to the CRM is an explicit choice, exactly as ``discover`` requires ``--commit`` to persist.
    """
    if not config.crm_token:
        print("ERROR: CRM_TOKEN is not set.", file=sys.stderr)
        return 2

    client = CrmIngestClient(
        base_url=config.crm_base_url, token=config.crm_token, user_agent=config.user_agent
    )
    try:
        queue = client.fetch_enrichment_queue(limit)
    finally:
        client.close()

    if not queue:
        print("No businesses with a website URL to audit — nothing to do.", file=sys.stderr)
        return 0

    mode = "COMMIT" if commit else "DRY RUN"
    print(f"[{mode}] Auditing {len(queue)} website(s)...", file=sys.stderr)

    process = CrawlerProcess(
        settings={
            "ITEM_PIPELINES": {"scraper.website_intelligence.pipeline.SubmitAuditPipeline": 300},
            "CRM_BASE_URL": config.crm_base_url,
            "CRM_TOKEN": config.crm_token,
            "CRM_USER_AGENT": config.user_agent,
            # The pipeline's write gate. Absent/False → the crawl runs and reports, writes nothing.
            "CRM_COMMIT": commit,
            "TELNETCONSOLE_ENABLED": False,
            "LOG_LEVEL": "ERROR",
        }
    )
    process.crawl(WebsiteAuditSpider, queue=queue, user_agent=config.user_agent)
    process.start()  # blocks until the crawl finishes

    if commit:
        print(f"Done — submitted audits for up to {len(queue)} website(s).", file=sys.stderr)
    else:
        print(
            f"Done — DRY RUN: audited up to {len(queue)} website(s), nothing written to the CRM. "
            "Re-run with --commit to submit.",
            file=sys.stderr,
        )
    return 0
