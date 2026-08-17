"""Assemble a website audit into the CRM `PUT /leads/{id}/website-enrichment` payload.

The payload's typed fields are the approved core signals; everything else (extended page types, more
socials, contact form, maps embed, crawl metadata, and future SEO/mobile/perf checks) goes in the
`audit` blob — so new capabilities can be added without changing the CRM schema.
"""

from __future__ import annotations

from parsel import Selector

from scraper import __version__
from scraper.website_intelligence import detectors

PROVIDER = "scrapy-website-intelligence"


def build_audit(html: str, final_url: str, status: int) -> dict:
    """Full audit from a fetched homepage. `status` is the HTTP status of the homepage response."""
    reachable = True  # we received a response
    https = final_url.lower().startswith("https")

    # A broken homepage (4xx/5xx) is reachable but yields no usable structure/content.
    if status >= 400:
        return _payload(
            reachable=True,
            https=https,
            pages={},
            emails=[],
            phones=[],
            socials={},
            title=None,
            description=None,
            contact_form=False,
            maps_embed=False,
            extra={"homepage_status": status, "final_url": final_url},
        )

    sel = Selector(text=html)
    links = detectors._links(sel, final_url)
    pages = detectors.classify_pages(links)

    return _payload(
        reachable=reachable,
        https=https,
        pages=pages,
        emails=detectors.extract_emails(html, sel),
        phones=detectors.extract_phones(sel),
        socials=detectors.extract_socials(links),
        title=detectors.extract_title(sel),
        description=detectors.extract_description(sel),
        contact_form=detectors.has_contact_form(sel),
        maps_embed=detectors.has_maps_embed(sel),
        extra={"homepage_status": status, "final_url": final_url},
    )


def unreachable_audit() -> dict:
    """The website did not respond (DNS/timeout/connection error)."""
    return _payload(
        reachable=False,
        https=False,
        pages={},
        emails=[],
        phones=[],
        socials={},
        title=None,
        description=None,
        contact_form=False,
        maps_embed=False,
        extra={"unreachable": True},
    )


def _payload(
    *,
    reachable: bool,
    https: bool,
    pages: dict[str, bool],
    emails: list[str],
    phones: list[str],
    socials: dict[str, str],
    title: str | None,
    description: str | None,
    contact_form: bool,
    maps_embed: bool,
    extra: dict,
) -> dict:
    # Extended / future signals live in `audit` (typed core stays first-class on the CRM record).
    audit_blob = {
        "portfolio_page": pages.get("portfolio", False),
        "team_page": pages.get("team", False),
        "faq_page": pages.get("faq", False),
        "privacy_page": pages.get("privacy", False),
        "terms_page": pages.get("terms", False),
        "tiktok_url": socials.get("tiktok"),
        "contact_form": contact_form,
        "google_maps_embed": maps_embed,
        **extra,
    }

    return {
        "website_reachable": reachable,
        "https_enabled": https,
        "about_page": pages.get("about", False),
        "contact_page": pages.get("contact", False),
        "services_page": pages.get("services", False),
        "careers_page": pages.get("careers", False),
        "blog_page": pages.get("blog", False),
        "emails_found": emails,
        "phones_found": phones,
        "facebook_url": socials.get("facebook"),
        "linkedin_url": socials.get("linkedin"),
        "instagram_url": socials.get("instagram"),
        "youtube_url": socials.get("youtube"),
        # Truncate to the CRM's field limits so a long title/description never 422s the submission.
        "website_title": title[:255] if title else None,
        "website_description": description[:2000] if description else None,
        "audit": audit_blob,
        "provider": PROVIDER,
        "provider_version": __version__,
    }
