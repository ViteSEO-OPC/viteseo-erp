"""Normalizes a lead's `website` field into a URL Scrapy can safely request.

The CRM stores `website` as unvalidated free text — StoreLeadRequest, UpdateLeadRequest, and
IngestDiscoveredLeadsRequest all accept ``['nullable', 'string', ...]``, and the frontend Create Lead
form does the same (`optionalText()`, no `.url()` check). That's by design: it's the same shape a
human can type into a form field. Enforcing a real URL format is this consumer's job, not the CRM's —
``scrapy.Request`` raises ``ValueError: Missing scheme in request url`` for anything schemeless, which
would otherwise crash the crawl's `start()` generator on real local data (e.g. "www.example.com",
"ASD", "This is just a Test"). Having a scheme is not sufficient either — see ``_is_requestable``.
"""

from __future__ import annotations

import re
from urllib.parse import urlsplit

# One or more dot-separated labels, each 1-63 chars, alphanumeric/hyphen, never starting or ending
# with a hyphen. Matches real hostnames ("www.capitallegal.ph", "google.com") while rejecting bare
# words with no dot ("ASD", "ASDASDSAD") — whitespace ("This is just a Test") is rejected because
# none of the characters in a label class match a space.
_HOSTNAME_RE = re.compile(
    r"^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+$"
)


def _is_requestable(url: str) -> bool:
    """True when ``scrapy.Request`` can parse ``url`` without raising.

    Having a scheme is not enough. Scrapy parses the URL with ``urlsplit``, and two separate things
    there raise ``ValueError`` on free-text values that *do* start with http(s):

    * an authority containing an unmatched bracket -> "Invalid IPv6 URL", raised by ``urlsplit``
      itself ("https://example.com [old site]", a pasted markdown link, a stray "[" typo);
    * a non-numeric or out-of-range port -> raised only when the port is read, so ``urlsplit``
      succeeding is not on its own proof the URL is usable.

    Both raise inside the spider's ``start()`` generator, which aborts the entire crawl — every
    remaining lead in the queue goes unaudited. Asking the same question here, up front, keeps such
    a value out of Scrapy altogether. A hostless value ("https://", "https://:80") is rejected too:
    it parses, but there is nothing to request.
    """
    try:
        parts = urlsplit(url)
        parts.port  # noqa: B018 - property access is the validation; raises on a malformed port
    except ValueError:
        return False

    return bool(parts.hostname)


def normalize_website_url(raw: str) -> str | None:
    """Return a request-able URL for ``raw``, or ``None`` if it isn't recognizable as one.

    Already-schemed values (``http://``/``https://``) are preserved as-is when they actually parse —
    a bad *host* behind a real scheme is a network-layer failure Scrapy already handles via
    ``on_error`` -> ``unreachable_audit()``, so this function rejects only what Scrapy cannot even
    construct a request from (see ``_is_requestable``). A bare hostname ("www.example.com") is
    promoted to ``https://`` — the scheme ``audit.py`` already assumes. Anything else never reaches
    ``scrapy.Request`` at all.
    """
    value = raw.strip()
    if not value:
        return None

    lowered = value.lower()
    if lowered.startswith("http://") or lowered.startswith("https://"):
        candidate = value
    elif _HOSTNAME_RE.match(value):
        candidate = f"https://{value}"
    else:
        return None

    # Single exit for both branches, so every non-None return is a URL Scrapy can request.
    return candidate if _is_requestable(candidate) else None
