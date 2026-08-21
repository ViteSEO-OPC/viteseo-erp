"""Pure website-audit detectors.

These functions take raw HTML (and its base URL) and return structured audit signals. They are
deliberately provider-independent and side-effect free, so they're unit-testable against recorded HTML
and reusable as the Website Intelligence capabilities grow (new page types, more socials, SEO, …).
"""

from __future__ import annotations

import re
from urllib.parse import urljoin
from parsel import Selector

# Page type → keywords found in a link's path or text. Word-boundary matched to avoid false positives.
PAGE_KEYWORDS: dict[str, list[str]] = {
    "about": ["about", "about us", "who we are", "our story", "company"],
    "contact": ["contact", "contact us", "get in touch", "reach us"],
    "services": ["services", "our services", "what we do", "solutions", "products"],
    "careers": ["careers", "career", "jobs", "join us", "work with us", "hiring", "vacancies"],
    "blog": ["blog", "news", "articles", "insights"],
    "portfolio": ["portfolio", "our work", "projects", "case studies", "gallery"],
    "team": ["team", "our team", "people", "leadership", "staff"],
    "faq": ["faq", "faqs", "frequently asked", "help center"],
    "privacy": ["privacy", "privacy policy"],
    "terms": ["terms", "terms of service", "terms and conditions", "terms of use"],
}

SOCIAL_DOMAINS: dict[str, list[str]] = {
    "facebook": ["facebook.com", "fb.com"],
    "instagram": ["instagram.com"],
    "linkedin": ["linkedin.com"],
    "youtube": ["youtube.com", "youtu.be"],
    "tiktok": ["tiktok.com"],
}

_EMAIL_RE = re.compile(r"[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}")
_IMAGE_EXT = (".png", ".jpg", ".jpeg", ".gif", ".svg", ".webp")
_PHONE_RE = re.compile(r"\+?\d[\d\s().\-]{6,}\d")


def _links(sel: Selector, base_url: str) -> list[tuple[str, str]]:
    """(absolute href, link text) for every anchor."""
    out = []
    for a in sel.css("a"):
        href = a.attrib.get("href", "").strip()
        if not href or href.startswith(("#", "javascript:")):
            continue
        text = " ".join(a.css("::text").getall()).strip()
        out.append((urljoin(base_url, href), text))
    return out


def _normalize(href: str, text: str) -> str:
    """Path + text as space-separated words for word-boundary keyword matching."""
    path = re.sub(r"[^a-z0-9]+", " ", href.lower())
    return f"{path} {text.lower()}".strip()


def classify_pages(links: list[tuple[str, str]]) -> dict[str, bool]:
    """Which page types the site links to (About/Contact/Services/…), from nav + body links."""
    haystacks = [_normalize(h, t) for h, t in links]
    result: dict[str, bool] = {}
    for page, keywords in PAGE_KEYWORDS.items():
        found = False
        for kw in keywords:
            pattern = r"\b" + re.escape(kw) + r"\b"
            if any(re.search(pattern, hay) for hay in haystacks):
                found = True
                break
        result[page] = found
    return result


def extract_emails(html: str, sel: Selector) -> list[str]:
    emails = set()
    for href in sel.css('a[href^="mailto:"]::attr(href)').getall():
        emails.add(href.split("mailto:", 1)[1].split("?", 1)[0].strip().lower())
    for m in _EMAIL_RE.findall(html):
        emails.add(m.lower())
    return sorted(e for e in emails if e and not e.endswith(_IMAGE_EXT))


def extract_phones(sel: Selector) -> list[str]:
    phones = set()
    for href in sel.css('a[href^="tel:"]::attr(href)').getall():
        phones.add(re.sub(r"\s+", "", href.split("tel:", 1)[1].strip()))
    return sorted(p for p in phones if len(re.sub(r"\D", "", p)) >= 7)


def extract_socials(links: list[tuple[str, str]]) -> dict[str, str]:
    """First URL found per social platform."""
    found: dict[str, str] = {}
    for href, _ in links:
        low = href.lower()
        for platform, domains in SOCIAL_DOMAINS.items():
            if platform in found:
                continue
            if any(d in low for d in domains):
                found[platform] = href
    return found


def has_contact_form(sel: Selector) -> bool:
    return bool(sel.css("form"))


def has_maps_embed(sel: Selector) -> bool:
    srcs = sel.css("iframe::attr(src)").getall()
    return any("google.com/maps" in s or "maps.google" in s for s in srcs)


def extract_title(sel: Selector) -> str | None:
    title = sel.css("title::text").get()
    return title.strip() if title and title.strip() else None


def extract_canonical(sel: Selector, base_url: str) -> str | None:
    """The URL the page claims for itself: `<link rel="canonical">`, else `og:url`.

    A site can be reached at many addresses (a redirect chain, a directory's tracking link, an
    alternate domain kept alive after a rebrand). The canonical URL is the page's own statement of
    where it really lives, which is the address worth judging identity against — so a redirect into a
    parked or unrelated host is assessed on where it landed, not where the crawl was aimed. Relative
    values are resolved against the fetched URL; a self-referential canonical is normal and useful.
    """
    for css in ('link[rel="canonical"]::attr(href)', 'meta[property="og:url"]::attr(content)'):
        val = sel.css(css).get()
        if val and val.strip():
            return urljoin(base_url, val.strip())
    return None


def extract_description(sel: Selector) -> str | None:
    for css in ('meta[name="description"]::attr(content)', 'meta[property="og:description"]::attr(content)'):
        val = sel.css(css).get()
        if val and val.strip():
            return val.strip()
    return None
