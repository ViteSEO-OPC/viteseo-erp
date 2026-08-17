"""Website Intelligence detector + audit tests (pure, no network)."""

import pytest
import scrapy
from parsel import Selector

from scraper.website_intelligence import detectors
from scraper.website_intelligence.audit import build_audit, unreachable_audit
from scraper.website_intelligence.urls import normalize_website_url

SAMPLE_HTML = """
<html><head>
  <title>Acme Coffee Roasters</title>
  <meta name="description" content="Specialty coffee in Makati.">
</head><body>
  <nav>
    <a href="/about-us">About Us</a>
    <a href="/services">Our Services</a>
    <a href="/contact">Contact</a>
    <a href="/blog">Blog</a>
  </nav>
  <a href="https://facebook.com/acme">Facebook</a>
  <a href="https://instagram.com/acme">Instagram</a>
  <a href="mailto:hello@acme.com">Email us</a>
  <a href="tel:+63212345678">Call</a>
  <img src="/logo@2x.png">
  <form action="/subscribe"><input name="email"></form>
  <iframe src="https://www.google.com/maps/embed?pb=xyz"></iframe>
</body></html>
"""


def test_build_audit_full_page():
    payload = build_audit(SAMPLE_HTML, "https://acme.com/", 200)

    assert payload["website_reachable"] is True
    assert payload["https_enabled"] is True
    assert payload["about_page"] is True
    assert payload["contact_page"] is True
    assert payload["services_page"] is True
    assert payload["blog_page"] is True
    assert payload["careers_page"] is False
    assert payload["website_title"] == "Acme Coffee Roasters"
    assert payload["website_description"] == "Specialty coffee in Makati."
    assert "hello@acme.com" in payload["emails_found"]
    assert payload["facebook_url"] == "https://facebook.com/acme"
    assert payload["instagram_url"] == "https://instagram.com/acme"
    assert payload["phones_found"] == ["+63212345678"]
    assert payload["provider"] == "scrapy-website-intelligence"
    # Extended signals live in the audit blob.
    assert payload["audit"]["contact_form"] is True
    assert payload["audit"]["google_maps_embed"] is True


def test_emails_filter_image_false_positives():
    emails = detectors.extract_emails(SAMPLE_HTML, Selector(text=SAMPLE_HTML))
    assert all(not e.endswith((".png", ".jpg")) for e in emails)


def test_classify_pages_word_boundary_no_false_positive():
    # "aboutique" must NOT match the "about" keyword.
    links = [("https://x.com/aboutique", "Boutique")]
    assert detectors.classify_pages(links)["about"] is False


def test_build_audit_broken_homepage_is_reachable_no_pages():
    payload = build_audit("<html></html>", "https://acme.com/", 503)
    assert payload["website_reachable"] is True
    assert payload["about_page"] is False
    assert payload["audit"]["homepage_status"] == 503


def test_unreachable_audit():
    payload = unreachable_audit()
    assert payload["website_reachable"] is False
    assert payload["https_enabled"] is False
    assert payload["audit"]["unreachable"] is True


# ── normalize_website_url — the queue's `website` field is unvalidated free text ────────────────


def test_normalize_https_url_passes_through_unchanged():
    assert normalize_website_url("https://example.com") == "https://example.com"


def test_normalize_http_url_passes_through_unchanged():
    # http is preserved, never rewritten to https — a bad host behind either scheme is a
    # network-layer failure Scrapy already handles via on_error(), not this function's job.
    assert normalize_website_url("http://example.com") == "http://example.com"


def test_normalize_schemeless_hostname_gets_https():
    assert normalize_website_url("www.example.com") == "https://www.example.com"


def test_normalize_schemeless_bare_domain_gets_https():
    # A real domain shape (dot-separated labels) is enough, even without a "www." prefix — this is
    # the same shape as "google.com" appearing (incorrectly, but validly-shaped) in real lead data.
    assert normalize_website_url("google.com") == "https://google.com"


def test_normalize_rejects_word_with_no_dot():
    assert normalize_website_url("ASD") is None


def test_normalize_rejects_longer_word_with_no_dot():
    assert normalize_website_url("ASDASDSAD") is None


def test_normalize_rejects_freeform_text_with_whitespace():
    assert normalize_website_url("This is just a Test") is None


# ── Scheme-bearing values that scrapy.Request cannot parse ──────────────────────────────────────
#
# A scheme alone does not make a value requestable. These inputs all start with http(s) and would
# reach scrapy.Request, where they raise ValueError *inside* the spider's start() generator and
# abort the whole crawl. Each one below is a value that really did crash before this was fixed.


@pytest.mark.parametrize(
    "value",
    [
        "https://example.com [old site]",  # a note appended in the free-text field
        "https://[example.com](https://example.com)",  # a pasted markdown link
        "http://[www.site.com",  # stray opening bracket
        "http://exa]mple.com",  # stray closing bracket
        "http://[",  # bracket alone
    ],
)
def test_normalize_rejects_unparseable_bracket_values(value):
    """Unmatched brackets make urlsplit raise 'Invalid IPv6 URL' — reject before Scrapy sees it."""
    assert normalize_website_url(value) is None


@pytest.mark.parametrize("value", ["https://example.com:99999", "https://example.com:abc"])
def test_normalize_rejects_malformed_port(value):
    """A bad port parses cleanly and only raises when read — urlsplit succeeding is not enough."""
    assert normalize_website_url(value) is None


@pytest.mark.parametrize("value", ["https://", "http://", "https://:80"])
def test_normalize_rejects_scheme_with_no_host(value):
    """Parseable but nothing to request."""
    assert normalize_website_url(value) is None


@pytest.mark.parametrize(
    "value",
    [
        "https://[::1]:8000/",  # genuine IPv6 literal — the bracket check must not overreach
        "https://[2001:db8::1]/",
        "https://example.com:8080/path?q=1",  # a valid port still passes
        "https://user:pw@example.com/x",
    ],
)
def test_normalize_keeps_valid_parseable_urls_unchanged(value):
    """The guard rejects only what Scrapy cannot construct — real URLs pass through untouched."""
    assert normalize_website_url(value) == value


def test_every_normalized_url_can_build_a_scrapy_request():
    """The invariant behind the fix: a non-None return is always requestable.

    This is the assertion that would have caught the original bug — it calls the real
    scrapy.Request, so any value the normalizer lets through must survive request construction.
    """
    candidates = [
        "https://example.com [old site]",
        "https://[example.com](https://example.com)",
        "http://[",
        "http://exa]mple.com",
        "https://example.com:99999",
        "https://example.com:abc",
        "https://",
        "www.example.com",
        "https://[::1]:8000/",
        "google.com",
        "ASD",
        "This is just a Test",
    ]

    for raw in candidates:
        url = normalize_website_url(raw)
        if url is None:
            continue
        scrapy.Request(url)  # must not raise


# ── A malformed value must not stop the rest of the queue ───────────────────────────────────────
#
# The original bug was not just "one lead fails". scrapy.Request is called inside the spider's
# start() async generator, so a raise there aborts the ENTIRE crawl: every lead queued after the
# bad one is silently never audited. These tests pin valid -> malformed -> valid ordering.


class _RecordingCrawlerProcess:
    """Stands in for Scrapy's CrawlerProcess — records the queue instead of crawling."""

    instances: list["_RecordingCrawlerProcess"] = []

    def __init__(self, settings=None):
        self.settings = settings or {}
        self.queue = None
        self.started = False
        _RecordingCrawlerProcess.instances.append(self)

    def crawl(self, spider_cls, queue=None, user_agent=""):
        self.queue = queue

    def start(self):
        self.started = True


class _QueueOnlyClient:
    """CRM client stand-in: serves the queue, records any write attempt."""

    writes: list = []

    def __init__(self, *, base_url, token, user_agent):
        pass

    def fetch_enrichment_queue(self, limit):
        return [
            {"id": "1", "website": "https://good-one.com"},
            {"id": "2", "website": "https://example.com [old site]"},  # crashed the crawl before
            {"id": "3", "website": "https://good-two.com"},
        ]

    def close(self):
        pass


def _audit_config():
    from scraper.config import Config

    return Config(
        discovery_provider="openstreetmap",
        crm_base_url="http://localhost:8001/api/v1/crm",
        crm_token="test-token-not-real",
        overpass_url="",
        nominatim_url="",
        user_agent="test",
        request_timeout=30.0,
    )


def test_run_audit_skips_malformed_and_keeps_the_following_valid_lead(monkeypatch):
    """valid -> malformed -> valid: lead 2 is dropped, lead 3 still reaches the crawl."""
    from scraper.website_intelligence import runner

    _RecordingCrawlerProcess.instances = []
    monkeypatch.setattr(runner, "CrmIngestClient", _QueueOnlyClient)
    monkeypatch.setattr(runner, "CrawlerProcess", _RecordingCrawlerProcess)

    rc = runner.run_audit(_audit_config(), limit=50, commit=False)

    assert rc == 0
    process = _RecordingCrawlerProcess.instances[0]
    assert [item["id"] for item in process.queue] == ["1", "3"]  # lead 3 survived lead 2
    assert [item["website"] for item in process.queue] == [
        "https://good-one.com",
        "https://good-two.com",
    ]
    # The dry-run contract is unchanged by this fix.
    assert process.settings["CRM_COMMIT"] is False


def test_spider_start_yields_every_request_after_a_malformed_value_is_filtered():
    """Draining the real spider generator over the filtered queue must not raise."""
    import asyncio

    from scraper.website_intelligence.spider import WebsiteAuditSpider

    raw = [
        {"id": "1", "website": "https://good-one.com"},
        {"id": "2", "website": "https://example.com [old site]"},
        {"id": "3", "website": "https://good-two.com"},
    ]
    # Same filter run_audit applies before the crawl starts.
    queue = [
        {**item, "website": normalize_website_url(item["website"])}
        for item in raw
        if normalize_website_url(item["website"])
    ]

    async def drain():
        return [request.url async for request in WebsiteAuditSpider(queue=queue).start()]

    assert asyncio.run(drain()) == ["https://good-one.com", "https://good-two.com"]
