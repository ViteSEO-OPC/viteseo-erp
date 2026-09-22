"""SSRF guard (C-1). Offline: the resolver is substituted and HTTP goes to an httpx MockTransport.

What each group pins:
  * addresses — every non-public class is refused, including IPv4 hidden inside IPv6 encodings;
  * static URL policy — scheme, userinfo, port, internal names, single-label names, IP literals;
  * resolution — ALL answers must be public; exotic numeric literals are decoded and refused;
  * safe_fetch — connects to the pinned IP (never re-resolves), re-validates every redirect hop,
    refuses redirects into private space / other ports, caps the body, bounds redirect count;
  * Scrapy — the downloader middleware and the connection resolver both refuse private targets;
  * fetch_candidate — a refused URL is reported as unreachable and makes no request at all.
"""

from __future__ import annotations

import socket

import httpx
import pytest

from scraper import net_guard
from scraper.net_guard import (
    SafeUrlDownloaderMiddleware,
    UnsafeUrl,
    check_url,
    is_public_ip,
    require_public_address,
    resolve_public,
    safe_fetch,
    validate_url,
)

PUBLIC = "93.184.216.34"


def fake_resolver(mapping: dict[str, list[str]]):
    """A getaddrinfo stand-in answering from ``mapping``; unknown names fail like NXDOMAIN."""
    calls: list[str] = []

    def resolve(host, port, type=0, **_):  # noqa: A002 - mirrors socket.getaddrinfo
        calls.append(host)
        if host not in mapping:
            raise socket.gaierror("no such host")
        family = socket.AF_INET
        return [(family, socket.SOCK_STREAM, 6, "", (address, port)) for address in mapping[host]]

    resolve.calls = calls  # type: ignore[attr-defined]
    return resolve


@pytest.fixture
def resolver(monkeypatch):
    def install(mapping):
        fake = fake_resolver(mapping)
        monkeypatch.setattr(net_guard, "RESOLVE", fake)
        return fake

    return install


# ── addresses ──────────────────────────────────────────────────────────────────────────────────

@pytest.mark.parametrize(
    "address",
    [
        "127.0.0.1", "127.8.9.10", "0.0.0.0", "10.0.0.1", "172.16.0.1", "172.31.255.255",
        "192.168.1.1", "100.64.0.1", "169.254.169.254", "169.254.0.1", "224.0.0.1",
        "255.255.255.255", "192.0.0.1", "198.18.0.1",
        "::1", "::", "fc00::1", "fd12:3456::1", "fe80::1", "ff02::1",
        "::ffff:127.0.0.1", "::ffff:10.0.0.1", "::ffff:169.254.169.254",
        "64:ff9b::a9fe:a9fe",  # NAT64 of 169.254.169.254
        "2002:7f00:1::1",  # 6to4 of 127.0.0.1
        "2002:a00:1::1",  # 6to4 of 10.0.0.1
        "not-an-ip",
    ],
)
def test_non_public_addresses_are_refused(address):
    assert is_public_ip(address) is False


@pytest.mark.parametrize("address", [PUBLIC, "8.8.8.8", "1.1.1.1", "2606:4700:4700::1111"])
def test_public_addresses_are_allowed(address):
    assert is_public_ip(address) is True


# ── static URL policy ──────────────────────────────────────────────────────────────────────────

@pytest.mark.parametrize(
    "url",
    [
        "file:///etc/passwd", "gopher://example.com/", "ftp://example.com/", "data:text/html,x",
        "javascript:alert(1)", "dict://example.com/", "ws://example.com/",
        "http://localhost/", "http://LOCALHOST./", "http://foo.localhost/", "http://printer.local/",
        "http://metadata.google.internal/", "http://db.lan/", "http://router.home.arpa/",
        "http://viteseo-mysql/", "http://gotenberg:3000/", "http://2130706433/",
        "http://127.0.0.1/", "http://[::1]/", "http://[::ffff:127.0.0.1]/",
        "http://169.254.169.254/latest/meta-data/", "http://10.0.0.5/", "http://192.168.0.1/",
        "http://user:pass@example.com/", "http://user@example.com/",
        "http://example.com:8080/", "https://example.com:22/", "http://example.com:3308/",
        "http://example.com:0/", "http://[::1", "https://", "http://:80/",
    ],
)
def test_unsafe_urls_are_refused_statically(url):
    with pytest.raises(UnsafeUrl):
        check_url(url)


@pytest.mark.parametrize(
    "url",
    ["http://example.com/", "https://example.com/", "https://example.com:443/x?y=1",
     "http://example.com:80/", f"http://{PUBLIC}/", "HTTPS://Example.COM/"],
)
def test_ordinary_business_urls_pass_statically(url):
    scheme, host, port = check_url(url)
    assert scheme in {"http", "https"} and port in {80, 443} and host == host.lower()


# ── resolution ─────────────────────────────────────────────────────────────────────────────────

def test_every_resolved_address_must_be_public(resolver):
    resolver({"good.example": [PUBLIC], "evil.example": ["10.0.0.1"], "mixed.example": [PUBLIC, "127.0.0.1"]})
    assert resolve_public("good.example", 443) == [PUBLIC]
    with pytest.raises(UnsafeUrl):
        resolve_public("evil.example", 443)
    # One private answer among public ones is still refused: that is how split answers hide a target.
    with pytest.raises(UnsafeUrl):
        resolve_public("mixed.example", 443)


def test_unresolvable_host_is_refused(resolver):
    resolver({})
    with pytest.raises(UnsafeUrl):
        validate_url("https://nowhere.example/")


@pytest.mark.parametrize("url", ["http://0x7f.1/", "http://0177.0.0.1/", "http://127.1/", "http://10.1/"])
def test_exotic_numeric_literals_are_decoded_by_the_os_resolver_and_refused(url):
    # Real getaddrinfo: numeric forms are parsed locally (inet_aton), no DNS traffic.
    with pytest.raises(UnsafeUrl):
        validate_url(url)


# ── safe_fetch ─────────────────────────────────────────────────────────────────────────────────

def mock_transport(handler):
    seen: list[httpx.Request] = []

    def wrapped(request: httpx.Request) -> httpx.Response:
        seen.append(request)
        return handler(request)

    transport = httpx.MockTransport(wrapped)
    transport.seen = seen  # type: ignore[attr-defined]
    return transport


def test_fetch_connects_to_the_pinned_ip_with_the_real_host_and_sni(resolver):
    resolver({"shop.example": [PUBLIC]})
    transport = mock_transport(lambda r: httpx.Response(200, text="<title>Shop</title>"))

    result = safe_fetch("https://shop.example/about", user_agent="ua", transport=transport)

    request = transport.seen[0]
    assert request.url.host == PUBLIC  # the client never resolves the name itself
    assert request.headers["host"] == "shop.example"
    assert request.extensions.get("sni_hostname") == "shop.example"
    assert result.url == "https://shop.example/about"
    assert result.status_code == 200 and "Shop" in result.text


def test_a_redirect_into_private_space_is_refused_before_it_is_followed(resolver):
    resolver({"shop.example": [PUBLIC]})
    transport = mock_transport(
        lambda r: httpx.Response(302, headers={"location": "http://127.0.0.1:8001/api/v1/auth/me"})
    )
    with pytest.raises(UnsafeUrl):
        safe_fetch("https://shop.example/", user_agent="ua", transport=transport)
    assert len(transport.seen) == 1  # the private hop was never requested


@pytest.mark.parametrize(
    "location",
    ["http://169.254.169.254/latest/meta-data/", "http://viteseo-mysql/", "http://shop.example:3308/",
     "file:///etc/passwd", "http://inner.example/"],
)
def test_every_redirect_hop_is_revalidated(resolver, location):
    resolver({"shop.example": [PUBLIC], "inner.example": ["192.168.1.10"]})
    transport = mock_transport(lambda r: httpx.Response(301, headers={"location": location}))
    with pytest.raises(UnsafeUrl):
        safe_fetch("https://shop.example/", user_agent="ua", transport=transport)
    assert len(transport.seen) == 1


def test_dns_rebinding_between_hops_is_refused(resolver, monkeypatch):
    # First answer public, second answer private — the classic rebinding pattern. The second hop is
    # re-resolved AND re-validated, and the first hop's connection went to the pinned public IP.
    answers = iter([[PUBLIC], ["127.0.0.1"]])

    def rebinding(host, port, type=0, **_):  # noqa: A002
        return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", (a, port)) for a in next(answers)]

    monkeypatch.setattr(net_guard, "RESOLVE", rebinding)
    transport = mock_transport(
        lambda r: httpx.Response(302, headers={"location": "https://shop.example/next"})
        if r.url.path == "/" else httpx.Response(200, text="secret")
    )
    with pytest.raises(UnsafeUrl):
        safe_fetch("https://shop.example/", user_agent="ua", transport=transport)
    assert [r.url.host for r in transport.seen] == [PUBLIC]


def test_a_safe_redirect_is_followed_and_reported_by_name(resolver):
    resolver({"old.example": [PUBLIC], "new.example": ["8.8.8.8"]})
    transport = mock_transport(
        lambda r: httpx.Response(301, headers={"location": "https://new.example/home"})
        if r.headers["host"] == "old.example" else httpx.Response(200, text="ok")
    )
    result = safe_fetch("http://old.example/", user_agent="ua", transport=transport)
    assert result.url == "https://new.example/home"
    assert [r.url.host for r in transport.seen] == [PUBLIC, "8.8.8.8"]


def test_redirect_loops_are_bounded(resolver):
    resolver({"loop.example": [PUBLIC]})
    transport = mock_transport(lambda r: httpx.Response(302, headers={"location": "/again"}))
    with pytest.raises(UnsafeUrl):
        safe_fetch("https://loop.example/", user_agent="ua", transport=transport, max_redirects=3)
    assert len(transport.seen) == 4


def test_the_body_is_capped(resolver):
    resolver({"big.example": [PUBLIC]})
    transport = mock_transport(lambda r: httpx.Response(200, content=b"a" * 50_000))
    result = safe_fetch("https://big.example/", user_agent="ua", transport=transport, max_bytes=1_000)
    assert result.truncated is True and len(result.text) == 1_000


def test_an_unsafe_start_url_makes_no_request(resolver):
    resolver({"inner.example": ["10.1.2.3"]})
    transport = mock_transport(lambda r: httpx.Response(200))
    for url in ("http://inner.example/", "http://127.0.0.1/", "http://example.com:8080/"):
        with pytest.raises(UnsafeUrl):
            safe_fetch(url, user_agent="ua", transport=transport)
    assert transport.seen == []


# ── fetch_candidate (website discovery) ────────────────────────────────────────────────────────

def test_a_refused_candidate_reads_as_unreachable_and_leaks_nothing(resolver):
    from scraper.website_discovery.fetcher import fetch_candidate

    resolver({"inner.example": ["172.17.0.1"]})
    transport = mock_transport(lambda r: httpx.Response(200, text="<title>internal admin</title>"))
    result = fetch_candidate("http://inner.example/", user_agent="ua", transport=transport)
    assert result == {"website_reachable": False}
    assert transport.seen == []


def test_a_public_candidate_is_still_read(resolver):
    from scraper.website_discovery.fetcher import fetch_candidate

    resolver({"bakery.example": [PUBLIC]})
    transport = mock_transport(
        lambda r: httpx.Response(200, text="<html><head><title>Bakery</title></head><body>hi</body></html>")
    )
    result = fetch_candidate("https://bakery.example/", user_agent="ua", transport=transport)
    assert result["website_reachable"] is True
    assert result["website_title"] == "Bakery"
    assert result["final_url"] == "https://bakery.example/"


# ── Scrapy (website audit) ─────────────────────────────────────────────────────────────────────

def test_the_downloader_middleware_refuses_private_targets_and_redirect_hops(resolver):
    from scrapy import Request
    from scrapy.exceptions import IgnoreRequest

    resolver({"shop.example": [PUBLIC], "inner.example": ["10.0.0.9"]})
    middleware = SafeUrlDownloaderMiddleware()

    assert middleware.process_request(Request("https://shop.example/")) is None
    # A redirect re-enters the chain as a new Request for the Location URL.
    for url in ("http://inner.example/", "http://169.254.169.254/", "http://localhost:8001/",
                "https://shop.example:8443/"):
        with pytest.raises(IgnoreRequest):
            middleware.process_request(Request(url))


def test_the_connection_resolver_refuses_non_public_answers():
    # The callback the resolver attaches to every lookup the socket uses (DNS rebinding defence).
    assert require_public_address(PUBLIC, "shop.example") == PUBLIC
    for address in ("127.0.0.1", "10.0.0.1", "169.254.169.254"):
        with pytest.raises(UnsafeUrl):
            require_public_address(address, "shop.example")


def test_the_audit_crawl_is_configured_with_the_guard():
    from scrapy.utils.misc import load_object

    from scraper.website_intelligence.spider import WebsiteAuditSpider

    settings = WebsiteAuditSpider.custom_settings
    assert "scraper.net_guard.SafeUrlDownloaderMiddleware" in settings["DOWNLOADER_MIDDLEWARES"]
    assert settings["DOWNLOAD_MAXSIZE"] <= 2 * 1024 * 1024
    assert settings["REDIRECT_MAX_TIMES"] <= 5
    assert settings["HTTPPROXY_ENABLED"] is False

    resolver_class = load_object(net_guard.PUBLIC_ONLY_RESOLVER)
    from scrapy.resolver import CachingThreadedResolver

    assert issubclass(resolver_class, CachingThreadedResolver)
    source = open("scraper/website_intelligence/runner.py", encoding="utf-8").read()
    assert '"TWISTED_DNS_RESOLVER": PUBLIC_ONLY_RESOLVER' in source
