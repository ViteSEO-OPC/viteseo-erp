"""Fetch one candidate URL and report WHAT WAS SEEN — never what it means.

The same division the existing audit uses: the crawler observes, the CRM decides. Identity matching
lives in WebsiteIdentityVerifier and is not reimplemented here, so candidate verification and the
ordinary audit can never drift apart in how a business is recognised.

A light httpx fetch rather than Scrapy: a Scrapy CrawlerProcess can only be started once per process,
which makes it awkward inside a command that also talks to the CRM, and one page per candidate needs
none of its scheduling machinery. The extractors are the audit's own, reused unchanged.
"""

from __future__ import annotations

from typing import Any

import httpx
from parsel import Selector

from scraper.website_intelligence.detectors import (
    _links,
    extract_canonical,
    extract_description,
    extract_emails,
    extract_phones,
    extract_title,
)
from scraper.net_guard import UnsafeUrl, safe_fetch
from scraper.website_intelligence.urls import normalize_website_url


def fetch_candidate(
    url: str,
    *,
    user_agent: str,
    timeout: float = 20.0,
    transport: httpx.BaseTransport | None = None,
) -> dict[str, Any]:
    """Findings for one candidate, in the shape the CRM's verifier already consumes.

    Unreachable is a FINDING, not an error: `website_reachable: false` is exactly what the CRM needs
    to refuse a stale URL, and the measured Wikidata data had 4 dead links in 12. Nothing raises.

    SSRF-GUARDED. Candidate URLs come from public OSM/Wikidata tags and from the CRM, so the fetch
    goes through `net_guard.safe_fetch`: public addresses only, ports 80/443, every redirect hop
    re-validated, the validated IP pinned for the connection, the body capped. A URL the policy
    refuses is reported exactly like an unreachable one — the verifier needs no new vocabulary, and
    nothing about the internal target is echoed back.
    """
    normalized = normalize_website_url(url)
    if normalized is None:
        return {"website_reachable": False}

    try:
        response = safe_fetch(
            normalized, user_agent=user_agent, timeout=timeout, transport=transport
        )
    except UnsafeUrl:
        return {"website_reachable": False}
    except httpx.HTTPError:
        return {"website_reachable": False}

    if response.status_code >= 400:
        # A 403 from a bot-blocking host and a 404 from a lapsed domain are both "we could not read
        # a page here", which is all the verifier needs to refuse the candidate.
        return {"website_reachable": False, "final_url": response.url}

    selector = Selector(text=response.text)
    base = response.url
    links = _links(selector, base)

    return {
        "website_reachable": True,
        "website_title": extract_title(selector),
        "website_description": extract_description(selector),
        "final_url": base,
        "canonical_url": extract_canonical(selector, base),
        "phones_found": extract_phones(selector)[:10],
        "emails_found": extract_emails(response.text, selector)[:10],
    }
