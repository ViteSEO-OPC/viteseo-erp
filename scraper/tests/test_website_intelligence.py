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
    assert payload["final_url"] is None
    assert payload["canonical_url"] is None


# ── URL provenance — the CRM verifies business identity against these ───────────────────────────
#
# The audit reports WHERE it landed and what the page calls itself; it never decides whether the page
# belongs to the business (that is CRM business logic, and this is a strict data provider). Both are
# typed payload fields rather than `audit` blob keys because the CRM derives from them.


def test_build_audit_reports_the_final_url_it_landed_on():
    """`final_url` is the post-redirect URL Scrapy ended up at, not the one that was queued — so a
    redirect into a parked or unrelated host is assessed on where it actually went."""
    payload = build_audit(SAMPLE_HTML, "https://www.acmecoffee.com.ph/home", 200)

    assert payload["final_url"] == "https://www.acmecoffee.com.ph/home"


def test_build_audit_extracts_the_canonical_url():
    html = '<html><head><link rel="canonical" href="https://acme.com/"></head><body></body></html>'

    assert build_audit(html, "https://acme.com/landing?utm=x", 200)["canonical_url"] == "https://acme.com/"


def test_canonical_falls_back_to_og_url():
    html = '<html><head><meta property="og:url" content="https://acme.com/"></head><body></body></html>'

    assert build_audit(html, "https://acme.com/x", 200)["canonical_url"] == "https://acme.com/"


def test_a_relative_canonical_is_resolved_against_the_fetched_url():
    html = '<html><head><link rel="canonical" href="/ph/"></head><body></body></html>'

    assert build_audit(html, "https://acme.com/landing", 200)["canonical_url"] == "https://acme.com/ph/"


def test_canonical_is_none_when_the_page_declares_nothing():
    assert build_audit(SAMPLE_HTML, "https://acme.com/", 200)["canonical_url"] is None


def test_a_broken_homepage_still_reports_where_it_landed():
    """A 4xx/5xx page yields no structure, but knowing the host that served the error is still the
    evidence the CRM needs to decide the URL was not the business's site."""
    payload = build_audit("<html></html>", "https://parking.example/acme", 404)

    assert payload["final_url"] == "https://parking.example/acme"
    assert payload["canonical_url"] is None


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


# --- redirect handling: the audit must assess the page, not the redirect stub ----------------


def _audit_request(url: str = "http://www.southstardrug.com.ph/"):
    """The REAL request the spider emits, so these assertions cannot drift from it."""
    import asyncio

    from scraper.website_intelligence.spider import WebsiteAuditSpider

    async def drain():
        return [r async for r in WebsiteAuditSpider(queue=[{"id": "1", "website": url}]).start()]

    return asyncio.run(drain())[0]


def _redirect_middleware():
    from scrapy.downloadermiddlewares.redirect import RedirectMiddleware
    from scrapy.utils.test import get_crawler

    from scraper.website_intelligence.spider import WebsiteAuditSpider

    crawler = get_crawler(WebsiteAuditSpider)
    crawler.spider = WebsiteAuditSpider(queue=[])

    return RedirectMiddleware.from_crawler(crawler)


def test_the_audit_spider_does_not_disable_redirect_following():
    """`handle_httpstatus_all` is the FIRST condition RedirectMiddleware tests, and it returns the
    3xx before reading `Location`. Setting it made REDIRECT_ENABLED and REDIRECT_MAX_TIMES dead
    letters and audited every http -> https upgrade as its own redirect stub."""
    assert "handle_httpstatus_all" not in _audit_request().meta


def test_the_audit_spider_still_handles_every_error_status_itself():
    """The reason the blanket flag was there: a broken homepage must reach parse() and be recorded
    as reachable-but-broken, not dropped by HttpErrorMiddleware. Naming the error range keeps that
    exactly as it was."""
    handled = _audit_request().meta["handle_httpstatus_list"]

    assert set(range(400, 600)) <= set(handled)
    # …and the redirect range is deliberately NOT claimed, so the middleware still owns it.
    assert not set(range(300, 400)) & set(handled)


def test_a_redirect_is_followed_instead_of_being_audited_as_the_page():
    """The whole bug, end to end: a 301 must become a new Request for the destination, never a
    Response handed to parse() and scraped for its `<title>301 Moved Permanently</title>`."""
    request = _audit_request()
    response = scrapy.http.Response(
        request.url,
        status=301,
        headers={"Location": "https://www.southstardrug.com.ph/"},
        body=b"<html><head><title>301 Moved Permanently</title></head><body></body></html>",
    )

    result = _redirect_middleware().process_response(request, response)

    assert isinstance(result, scrapy.Request), "a 3xx must be followed, not delivered to parse()"
    assert result.url == "https://www.southstardrug.com.ph/"


def test_the_previous_meta_would_have_delivered_the_stub_to_parse():
    """The negative control. Without it the test above could pass for reasons unrelated to the
    flag — this pins that the flag is what was doing the damage."""
    request = _audit_request().replace(meta={"lead_id": "1", "handle_httpstatus_all": True})
    response = scrapy.http.HtmlResponse(
        request.url,
        status=301,
        headers={"Location": "https://www.southstardrug.com.ph/"},
        body=b"<html><head><title>301 Moved Permanently</title></head><body></body></html>",
        encoding="utf-8",
    )

    result = _redirect_middleware().process_response(request, response)

    assert isinstance(result, scrapy.http.Response)
    assert build_audit(result.text, result.url, result.status)["website_title"] == "301 Moved Permanently"


def test_a_followed_redirect_is_still_checked_by_the_ssrf_guard(monkeypatch):
    """Following redirects must not widen the network boundary. The hop re-enters the chain as a
    new Request, so the downloader middleware validates the DESTINATION before any connection."""
    import socket

    from scrapy.exceptions import IgnoreRequest

    from scraper import net_guard
    from scraper.net_guard import SafeUrlDownloaderMiddleware

    # A public answer for any name, so the safe hop is judged on policy rather than on whether the
    # test machine has DNS. The hostile hop below is a literal address and never reaches a resolver.
    # Signature mirrors socket.getaddrinfo, as net_guard calls it with `port` and `type`.
    def resolve(host, port, type=0, **_):  # noqa: A002 - mirrors socket.getaddrinfo
        return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("93.184.216.34", port))]

    monkeypatch.setattr(net_guard, "RESOLVE", resolve)

    guard = SafeUrlDownloaderMiddleware()
    request = _audit_request()

    safe = _redirect_middleware().process_response(
        request,
        scrapy.http.Response(request.url, status=301, headers={"Location": "https://shop.example/"}),
    )
    assert guard.process_request(safe) is None

    hostile = _redirect_middleware().process_response(
        request,
        scrapy.http.Response(request.url, status=302, headers={"Location": "http://169.254.169.254/latest/meta-data/"}),
    )
    with pytest.raises(IgnoreRequest):
        guard.process_request(hostile)
