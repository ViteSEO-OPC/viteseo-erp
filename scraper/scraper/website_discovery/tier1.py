"""Tier 1 candidate website discovery — deterministic domain probing, no vendor and no API key.

WHY THIS TIER EXISTS. Tier 0 reads what OpenStreetMap already knows: a `website` tag, or a
`brand:wikidata` QID whose entity carries P856. Both are LOOKUPS, not searches — an independent
business with neither tag gives Tier 0 nothing to look up, so Tier 0 cannot fail to find their site
because it never looks for one. Measured on the configured targets, `brand:wikidata` covers 64% of
pharmacies and **0% of dentists, clinics and salons**, which is precisely where genuine no-website
opportunities concentrate. A search that only ran Tier 0 has not searched those businesses at all.

WHAT IT DOES. It derives plausible hostnames from the business name the queue already returns
("Casa Verde Bistro PLC" -> `casaverdebistro.com`, `.ph`, `.com.ph`, `.net`) and keeps the ones that
resolve to an address of their own. Resolution is the whole test performed here: it is cheap, it
makes no HTTP request, and it answers the only question this tier is entitled to answer — does a
host by that name exist?

"OF THEIR OWN" IS LOAD-BEARING. `.ph` and `.com.ph` answer for every name, existent or not, so a
bare "did it resolve?" is not a test there at all. {@see wildcard_addresses} learns what each
registry says about a name that cannot exist and {@see is_wildcard_answer} discards hosts whose
answer is indistinguishable from it — otherwise every lead would carry two candidates that exist
only because the registry replied, and "nothing was found", the strongest negative evidence this
tier can produce, would become unreachable for Philippine domains.

WHY GUESSING IS SAFE HERE, AND WOULD NOT BE ANYWHERE ELSE. A resolved host is a CANDIDATE, not an
answer. It is fetched by the existing fetcher and adjudicated by the unmodified
WebsiteIdentityVerifier at the stricter discovered-candidate bar (confidence >= 65, margin >= 15).
A parked domain, a squatter, or an unrelated company that happens to own the name is REFUSED and
recorded as a rejection — which is better evidence than never having looked. There is no shape of
wrong guess that can write `has-website`, and none that can write `no-website`: absence is still
concluded only by a human reading the trail.

WHAT IT DELIBERATELY WILL NOT PROPOSE. Slugs shorter than `MIN_SLUG_LENGTH`, and slugs that are a
bare industry word ("pharmacy", "dental"), are skipped. Such a host almost certainly belongs to
somebody else, and every candidate proposed costs a fetch and dilutes the margin rule that keeps an
ambiguous answer from resolving to whichever page sorted first.
"""

from __future__ import annotations

import re
import secrets
import socket
import unicodedata
from typing import Callable, Iterable, Optional

#: Tier identifier, recorded on the search so an exhaustion claim says WHICH places were tried.
#: Matches LeadWebsiteCandidate::SOURCE_PROBE on the CRM side.
TIER_PROBE = "probe"

#: Tried in order of how a Philippine business is most likely to be registered.
TLDS = (".com", ".ph", ".com.ph", ".net")

#: Words that describe the legal wrapper, not the business. Stripped so "Casa Verde Bistro PLC" and
#: "Casa Verde Bistro" produce the same slug.
_LEGAL_SUFFIXES = {
    "inc", "incorporated", "corp", "corporation", "ltd", "limited", "plc", "llc",
    "co", "company", "group", "holdings", "enterprises", "ent", "sons",
}

#: A host built from one of these alone would belong to somebody else. Never proposed on its own.
_GENERIC_WORDS = {
    "pharmacy", "drugstore", "clinic", "dental", "dentist", "salon", "spa", "restaurant",
    "cafe", "coffee", "hotel", "store", "shop", "bakery", "hospital", "laundry", "bar",
}

#: Below this a slug is too generic to be worth a fetch — "abc.com" is not a lead's website.
MIN_SLUG_LENGTH = 6

#: At most two slugs x four TLDs. A cap, not a target: more guesses means more fetches and a weaker
#: margin between candidates, and the marginal guess is the least likely one to be right.
MAX_HOSTS_PER_LEAD = 8


def _ascii_words(name: str) -> list[str]:
    """The business name as lowercase ASCII words, with accents folded and `&` spelled out."""
    folded = unicodedata.normalize("NFKD", name or "")
    folded = "".join(ch for ch in folded if not unicodedata.combining(ch))
    folded = folded.replace("&", " and ")
    return [w for w in re.split(r"[^a-z0-9]+", folded.lower()) if w]


def significant_words(name: str) -> list[str]:
    """The words that actually identify the business — legal wrappers dropped.

    If a name is NOTHING but legal wrappers the words are kept as they were: an empty result would
    silently skip the lead, and a name like "Group Ltd" is better probed than ignored.
    """
    words = _ascii_words(name)
    kept = [w for w in words if w not in _LEGAL_SUFFIXES]
    return kept or words


def slug_variants(name: str) -> list[str]:
    """Candidate slugs for a business name, most specific first.

    Two at most: the whole name, and — when the name is long enough for the tail to be a qualifier
    rather than part of the identity — its first two words. "Casa Verde Bistro" is plausibly
    `casaverdebistro.com` or `casaverde.com`; adding `casa.com` would only buy a fetch of somebody
    else's domain.
    """
    words = significant_words(name)
    if not words:
        return []

    variants = ["".join(words)]
    if len(words) > 2:
        variants.append("".join(words[:2]))

    out: list[str] = []
    for slug in variants:
        if len(slug) < MIN_SLUG_LENGTH or slug in _GENERIC_WORDS:
            continue
        if slug not in out:
            out.append(slug)
    return out


def candidate_hosts(name: str) -> list[str]:
    """Hostnames worth testing for one business name, capped at `MAX_HOSTS_PER_LEAD`."""
    hosts: list[str] = []
    for slug in slug_variants(name):
        for tld in TLDS:
            host = f"{slug}{tld}"
            if host not in hosts:
                hosts.append(host)
    return hosts[:MAX_HOSTS_PER_LEAD]


def hosts_for_leads(leads: list[dict]) -> dict[str, list[str]]:
    """Lead id -> the hostnames to test for it. Pure; no network."""
    return {str(lead["id"]): candidate_hosts(str(lead.get("business") or "")) for lead in leads}


def tld_of(host: str) -> Optional[str]:
    """The TLD from `TLDS` that `host` sits under, longest first.

    Longest-first matters: `x.com.ph` ends with both `.ph` and `.com.ph`, and those are separate
    registries with separate wildcard behaviour. Matching the shorter one would compare a host
    against the wrong registry's sentinel.
    """
    for tld in sorted(TLDS, key=len, reverse=True):
        if host.endswith(tld):
            return tld
    return None


def addresses_for(host: str) -> set[str]:
    """Every address `host` resolves to, or an empty set when it does not resolve.

    A resolver failure is "does not resolve" rather than an exception: one unlucky lookup must not
    cost the batch its evidence, and a host wrongly skipped merely leaves the lead where it was.
    """
    try:
        infos = socket.getaddrinfo(host, None)
    except (OSError, UnicodeError):
        return set()
    return {info[4][0] for info in infos}


def wildcard_addresses(
    tlds: Iterable[str] = TLDS,
    resolver: Optional[Callable[[str], set[str]]] = None,
) -> dict[str, set[str]]:
    """Per-TLD addresses that mean NOTHING — what a name that cannot exist resolves to.

    SOME REGISTRIES ANSWER FOR EVERY NAME. Measured against both the system resolver and 8.8.8.8,
    `.ph` and `.com.ph` return 45.79.222.138 for any nonexistent label, so "it resolves" is not
    evidence of registration there — without this, Tier 1 would propose to every lead two candidates
    that exist only because the registry answers, and the trail could never show the one thing worth
    showing: that nothing was found.

    The probe label is RANDOM PER RUN. A fixed one could be registered by anyone (deliberately or
    by chance), which would silently switch wildcard detection off for that TLD.
    """
    lookup = resolver or addresses_for
    label = 'nx' + secrets.token_hex(8)

    out: dict[str, set[str]] = {}
    for tld in tlds:
        addrs = lookup(f'{label}{tld}')
        if addrs:
            out[tld] = addrs
    return out


def is_wildcard_answer(host: str, addrs: set[str], wildcards: dict[str, set[str]]) -> bool:
    """Whether `host`'s answer is indistinguishable from the registry answering for anything.

    SUBSET, NOT EQUALITY. A wildcard served round-robin can return a different member of the same
    address set on each lookup, so a host answering with one of them is still the wildcard. A host
    holding ANY address outside that set has something of its own and is kept.
    """
    if not addrs:
        return False
    tld = tld_of(host)
    sentinel = wildcards.get(tld) if tld else None

    return bool(sentinel) and addrs <= sentinel


def resolve_hosts(
    hosts: Iterable[str],
    timeout: float = 3.0,
    wildcards: Optional[dict[str, set[str]]] = None,
    resolver: Optional[Callable[[str], set[str]]] = None,
) -> set[str]:
    """The subset of `hosts` that resolve to an address of their own.

    DNS only — no HTTP request is made here. Whether a page can be read, and whether it belongs to
    the business, are the fetcher's and the verifier's questions respectively, and answering them
    here would duplicate logic that must not be allowed to drift.

    `wildcards` is detected once per call unless supplied; that is one extra lookup per TLD for the
    whole batch, not per lead.
    """
    unique = sorted({h for h in hosts if h})
    if not unique:
        return set()

    original = socket.getdefaulttimeout()
    socket.setdefaulttimeout(timeout)
    try:
        lookup = resolver or addresses_for
        sentinels = wildcard_addresses(TLDS, lookup) if wildcards is None else wildcards

        live: set[str] = set()
        for host in unique:
            addrs = lookup(host)
            if not addrs or is_wildcard_answer(host, addrs, sentinels):
                continue
            live.add(host)
    finally:
        socket.setdefaulttimeout(original)

    return live


def candidates_for(
    leads: list[dict],
    hosts_by_lead: dict[str, list[str]],
    live_hosts: set[str],
    exclude_urls_by_lead: Optional[dict[str, set[str]]] = None,
) -> dict[str, list[dict]]:
    """Lead id -> the probe candidates for it. Pure, so the assembly rules test without DNS.

    `exclude_urls_by_lead` carries what an earlier tier already proposed for that lead. A URL Tier 0
    read straight off the element is the same URL however many ways it can be derived, and proposing
    it twice would buy a second fetch of one page and split the margin rule against itself.
    """
    excluded = exclude_urls_by_lead or {}
    out: dict[str, list[dict]] = {}

    for lead in leads:
        lead_id = str(lead["id"])
        already = {u.strip().lower().rstrip("/") for u in excluded.get(lead_id, set())}
        found: list[dict] = []

        for host in hosts_by_lead.get(lead_id, []):
            if host not in live_hosts:
                continue
            url = f"https://{host}"
            if url.lower() in already:
                continue
            found.append({"url": url, "source": TIER_PROBE, "source_detail": f"dns:{host}"})

        out[lead_id] = found

    return out
