"""Normalizes a lead's `website` field into a URL Scrapy can safely request.

The CRM stores `website` as unvalidated free text — StoreLeadRequest, UpdateLeadRequest, and
IngestDiscoveredLeadsRequest all accept ``['nullable', 'string', ...]``, and the frontend Create Lead
form does the same (`optionalText()`, no `.url()` check). That's by design: it's the same shape a
human can type into a form field. Enforcing a real URL format is this consumer's job, not the CRM's —
``scrapy.Request`` raises ``ValueError: Missing scheme in request url`` for anything schemeless, which
would otherwise crash the crawl's `start()` generator on real local data (e.g. "www.example.com",
"ASD", "This is just a Test").
"""

from __future__ import annotations

import re

# One or more dot-separated labels, each 1-63 chars, alphanumeric/hyphen, never starting or ending
# with a hyphen. Matches real hostnames ("www.capitallegal.ph", "google.com") while rejecting bare
# words with no dot ("ASD", "ASDASDSAD") — whitespace ("This is just a Test") is rejected because
# none of the characters in a label class match a space.
_HOSTNAME_RE = re.compile(
    r"^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+$"
)


def normalize_website_url(raw: str) -> str | None:
    """Return a request-able URL for ``raw``, or ``None`` if it isn't recognizable as one.

    Already-schemed values (``http://``/``https://``) pass through unchanged — a bad host behind a
    real scheme is a network-layer failure Scrapy already handles via ``on_error`` ->
    ``unreachable_audit()``, not a value this function should reject. A bare hostname
    ("www.example.com") is promoted to ``https://`` — the scheme ``audit.py`` already assumes.
    Anything else never reaches ``scrapy.Request`` at all.
    """
    value = raw.strip()
    if not value:
        return None

    lowered = value.lower()
    if lowered.startswith("http://") or lowered.startswith("https://"):
        return value

    if _HOSTNAME_RE.match(value):
        return f"https://{value}"

    return None
