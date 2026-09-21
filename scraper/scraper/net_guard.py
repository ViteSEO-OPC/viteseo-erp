"""SSRF guard for every fetch whose URL came from data rather than from configuration.

WHY THIS EXISTS. The website crawlers fetch URLs that anyone can influence: a lead's `website` field
(typed by a user, ingested from a provider), candidate URLs from public OSM/Wikidata tags, and
redirects chosen by whatever server answers. The worker containers run on the HOST network, so an
unguarded fetch of `http://localhost:3308/`, `http://169.254.169.254/` or `http://viteseo-mysql/`
reaches infrastructure that is never meant to be addressable from data.

THE POLICY, in the order it is applied:

1. scheme is http or https, and the URL carries no userinfo (`user:pass@` is a classic confusion);
2. port is 80 or 443 — nothing else a public business website legitimately needs;
3. the host is not a local/internal NAME (`localhost`, `*.local`, `*.internal`, single-label names
   such as docker service names);
4. EVERY address the host resolves to is globally routable — loopback, RFC1918, CGNAT, link-local
   (incl. 169.254.169.254 cloud metadata), ULA, multicast, reserved, unspecified and the
   IPv4-in-IPv6 encodings of any of those are all refused. Resolution is what catches the exotic
   literals (`2130706433`, `0x7f.1`, `017700000001`) because the OS resolver decodes them for us.

DNS REBINDING is closed by never resolving twice: `safe_fetch` connects to the exact IP it validated
(Host header and TLS SNI still carry the real name, so virtual hosting and certificate checks work),
and the Scrapy path validates at the resolver the connection itself uses. Every redirect hop goes
through the whole policy again. Bodies are capped.

Nothing here reads configuration, credentials or the CRM; it only answers "may this URL be fetched".
"""

from __future__ import annotations

import ipaddress
import socket
from dataclasses import dataclass
from typing import Any, Callable, Optional
from urllib.parse import urljoin, urlsplit, urlunsplit

import httpx

ALLOWED_SCHEMES = frozenset({"http", "https"})
ALLOWED_PORTS = frozenset({80, 443})
DEFAULT_PORTS = {"http": 80, "https": 443}
MAX_REDIRECTS = 5
# A business homepage is well under this; anything larger is either not a homepage or an attack.
MAX_RESPONSE_BYTES = 2 * 1024 * 1024

# Names that only ever mean "something on this network". Checked before resolution so a hostile or
# split-horizon resolver cannot make them look public.
_BLOCKED_NAMES = frozenset({"localhost", "localhost.localdomain", "ip6-localhost", "ip6-loopback"})
_BLOCKED_SUFFIXES = (
    ".localhost", ".local", ".localdomain", ".internal", ".intranet", ".lan", ".home.arpa", ".corp",
)
_REDIRECT_CODES = frozenset({301, 302, 303, 307, 308})
_NAT64 = ipaddress.ip_network("64:ff9b::/96")

# The resolver. A module attribute so tests substitute it; production is the OS resolver.
RESOLVE: Callable[..., Any] = socket.getaddrinfo


class UnsafeUrl(ValueError):
    """The URL must not be fetched. The message is a short reason, never a response body."""


def is_public_ip(value: str) -> bool:
    """True only for a globally routable unicast address, after unwrapping IPv4-in-IPv6 forms."""
    try:
        ip = ipaddress.ip_address(value.split("%", 1)[0])
    except ValueError:
        return False

    if isinstance(ip, ipaddress.IPv6Address):
        embedded: Optional[ipaddress.IPv4Address] = None
        if ip.ipv4_mapped is not None:
            embedded = ip.ipv4_mapped
        elif ip.sixtofour is not None:
            embedded = ip.sixtofour
        elif ip.teredo is not None:
            embedded = ip.teredo[1]
        elif ip in _NAT64:
            embedded = ipaddress.IPv4Address(int(ip) & 0xFFFFFFFF)
        if embedded is not None and not _global_v4(embedded):
            return False

    if ip.is_multicast or ip.is_unspecified or ip.is_loopback or ip.is_link_local:
        return False
    return bool(ip.is_global)


def _global_v4(ip: ipaddress.IPv4Address) -> bool:
    return bool(ip.is_global) and not (ip.is_multicast or ip.is_loopback or ip.is_link_local)


def check_url(url: str) -> tuple[str, str, int]:
    """Static checks. Returns (scheme, host, port) or raises UnsafeUrl. Makes no network call."""
    try:
        parts = urlsplit(url.strip())
        port = parts.port
    except ValueError as exc:
        raise UnsafeUrl("malformed url") from exc

    scheme = (parts.scheme or "").lower()
    if scheme not in ALLOWED_SCHEMES:
        raise UnsafeUrl("scheme not allowed")
    if parts.username is not None or parts.password is not None:
        raise UnsafeUrl("userinfo not allowed")

    host = (parts.hostname or "").rstrip(".").lower()
    if not host:
        raise UnsafeUrl("missing host")

    effective_port = port if port is not None else DEFAULT_PORTS[scheme]
    if effective_port not in ALLOWED_PORTS:
        raise UnsafeUrl("port not allowed")

    if _is_ip_literal(host):
        if not is_public_ip(host):
            raise UnsafeUrl("non-public address")
        return scheme, host, effective_port

    if host in _BLOCKED_NAMES or host.endswith(_BLOCKED_SUFFIXES):
        raise UnsafeUrl("internal host name")
    if "." not in host:
        # Single-label names are LAN/docker service names ("viteseo-mysql") or numeric IP encodings
        # ("2130706433"). No public website is addressed that way.
        raise UnsafeUrl("single-label host")

    return scheme, host, effective_port


def resolve_public(host: str, port: int) -> list[str]:
    """Resolve ``host`` and require EVERY answer to be public. Raises UnsafeUrl otherwise."""
    if _is_ip_literal(host):
        if not is_public_ip(host):
            raise UnsafeUrl("non-public address")
        return [host]

    try:
        infos = RESOLVE(host, port, type=socket.SOCK_STREAM)
    except (OSError, UnicodeError) as exc:
        raise UnsafeUrl("unresolvable host") from exc

    addresses: list[str] = []
    for info in infos:
        address = str(info[4][0])
        if address not in addresses:
            addresses.append(address)

    if not addresses:
        raise UnsafeUrl("unresolvable host")
    # ALL, not ANY: a name answering with one public and one private address is exactly how a
    # rebinding or split-horizon trick hides a private target behind a public one.
    if not all(is_public_ip(address) for address in addresses):
        raise UnsafeUrl("resolves to a non-public address")
    return addresses


def validate_url(url: str) -> tuple[str, str, int, list[str]]:
    """Full check: static policy plus resolution. Returns (scheme, host, port, public addresses)."""
    scheme, host, port = check_url(url)
    return scheme, host, port, resolve_public(host, port)


@dataclass(frozen=True)
class SafeResponse:
    url: str
    status_code: int
    text: str
    truncated: bool


def safe_fetch(
    url: str,
    *,
    user_agent: str,
    timeout: float = 20.0,
    max_bytes: int = MAX_RESPONSE_BYTES,
    max_redirects: int = MAX_REDIRECTS,
    transport: Optional[httpx.BaseTransport] = None,
) -> SafeResponse:
    """GET ``url`` under the policy above. Raises UnsafeUrl or httpx.HTTPError.

    Redirects are followed HERE, one hop at a time, so every hop is validated and pinned. The HTTP
    client never resolves a name itself: it is handed the validated IP, which is what makes a DNS
    answer that changes between check and connect irrelevant.
    """
    current = url
    with httpx.Client(
        timeout=timeout,
        follow_redirects=False,
        trust_env=False,  # an HTTP(S)_PROXY in the environment would resolve names for us
        transport=transport,
    ) as client:
        for _ in range(max_redirects + 1):
            scheme, host, port, addresses = validate_url(current)
            parts = urlsplit(current)
            pinned = addresses[0]
            netloc = f"[{pinned}]" if ":" in pinned else pinned
            host_header = host if port == DEFAULT_PORTS[scheme] else f"{host}:{port}"
            if port != DEFAULT_PORTS[scheme]:
                netloc = f"{netloc}:{port}"
            target = urlunsplit((scheme, netloc, parts.path or "/", parts.query, ""))

            extensions = {"sni_hostname": host} if scheme == "https" else {}
            with client.stream(
                "GET",
                target,
                headers={"User-Agent": user_agent, "Host": host_header},
                extensions=extensions,
            ) as response:
                location = response.headers.get("location")
                if response.status_code in _REDIRECT_CODES and location:
                    current = urljoin(current, location)
                    continue

                body = bytearray()
                truncated = False
                for chunk in response.iter_bytes():
                    body.extend(chunk)
                    if len(body) > max_bytes:
                        del body[max_bytes:]
                        truncated = True
                        break

                encoding = response.encoding or "utf-8"
                try:
                    text = body.decode(encoding, errors="replace")
                except LookupError:
                    text = body.decode("utf-8", errors="replace")
                return SafeResponse(
                    url=current, status_code=response.status_code, text=text, truncated=truncated
                )

    raise UnsafeUrl("too many redirects")


def _is_ip_literal(host: str) -> bool:
    try:
        ipaddress.ip_address(host.strip("[]").split("%", 1)[0])
    except ValueError:
        return False
    return True


# ── Scrapy integration ───────────────────────────────────────────────────────────────────────────
#
# Two layers, because Scrapy resolves names itself:
#   * the downloader middleware refuses a request (including every redirect, which re-enters the
#     middleware chain as a new Request) whose URL fails the policy — before any connection;
#   * the resolver refuses to hand the connection a non-public address, which is the check that
#     holds against DNS rebinding, since it is the resolution the socket actually uses.


class SafeUrlDownloaderMiddleware:
    """Refuse any request, first hop or redirect, that the SSRF policy rejects."""

    def process_request(self, request: Any, spider: Any = None) -> None:
        from scrapy.exceptions import IgnoreRequest

        try:
            validate_url(request.url)
        except UnsafeUrl as exc:
            raise IgnoreRequest(f"blocked by SSRF policy: {exc}") from exc
        return None


def _public_only_resolver_class() -> type:
    """Built lazily so importing this module never imports Twisted."""
    from scrapy.resolver import CachingThreadedResolver

    class PublicOnlyResolver(CachingThreadedResolver):
        """The default Scrapy resolver, refusing any answer that is not a public address."""

        def getHostByName(self, name: str, timeout: Any = ()):  # noqa: N802 - Twisted API
            deferred = super().getHostByName(name, timeout)
            deferred.addCallback(require_public_address, name)
            return deferred

    return PublicOnlyResolver


def require_public_address(address: str, name: str = "") -> str:
    """Resolver callback: pass a public address through, fail the connection otherwise."""
    if not is_public_ip(str(address)):
        raise UnsafeUrl("resolves to a non-public address")
    return address


PUBLIC_ONLY_RESOLVER = "scraper.net_guard.PublicOnlyResolver"


_RESOLVER_CLASS: Optional[type] = None


def __getattr__(name: str) -> Any:
    # `TWISTED_DNS_RESOLVER` is loaded by dotted path; expose the lazily-built class under it.
    global _RESOLVER_CLASS
    if name == "PublicOnlyResolver":
        if _RESOLVER_CLASS is None:
            _RESOLVER_CLASS = _public_only_resolver_class()
        return _RESOLVER_CLASS
    raise AttributeError(name)
