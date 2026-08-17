"""Scrapy spider that audits business websites.

One request per lead's homepage; the audit findings are derived from that page (page structure is
classified from the site's own navigation links). The architecture is ready to follow internal pages
for deeper analysis later — a deferred future capability — without changing the audit contract.
"""

from __future__ import annotations

import scrapy

from scraper.website_intelligence.audit import build_audit, unreachable_audit


class WebsiteAuditSpider(scrapy.Spider):
    name = "website_audit"

    custom_settings = {
        "ROBOTSTXT_OBEY": True,
        "DOWNLOAD_TIMEOUT": 20,
        "RETRY_TIMES": 1,
        "CONCURRENT_REQUESTS": 4,
        "COOKIES_ENABLED": False,
        "REDIRECT_ENABLED": True,
    }

    def __init__(self, queue: list[dict] | None = None, user_agent: str = "", **kwargs):
        super().__init__(**kwargs)
        self._queue = queue or []
        self._user_agent = user_agent

    async def start(self):
        # Scrapy 2.13+ entry point (replaces start_requests).
        headers = {"User-Agent": self._user_agent} if self._user_agent else None
        for item in self._queue:
            yield scrapy.Request(
                item["website"],
                callback=self.parse,
                errback=self.on_error,
                headers=headers,
                meta={"lead_id": item["id"], "handle_httpstatus_all": True},
                dont_filter=True,
            )

    def parse(self, response):
        payload = build_audit(response.text, response.url, response.status)
        payload["lead_id"] = response.meta["lead_id"]
        yield payload

    def on_error(self, failure):
        # DNS failure / timeout / connection refused → the website is unreachable.
        payload = unreachable_audit()
        payload["lead_id"] = failure.request.meta["lead_id"]
        return [payload]
