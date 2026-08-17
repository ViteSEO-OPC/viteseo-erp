"""Normalizer — provider-neutral ``RawBusiness`` -> canonical ``NormalizedBusiness`` (CRM fit-data).

This is where discovery output becomes the exact shape the CRM ingest accepts. It performs website
detection (presence of a real URL -> ``website_status``) and passes through identity, contactability,
category, administrative location, coordinates, and provenance. It computes no score, KPI, ownership,
or engagement value — those are not part of the shape.
"""

from __future__ import annotations

from scraper.models import NormalizedBusiness, RawBusiness


def _looks_like_url(value: str) -> bool:
    value = value.strip().lower()
    return value.startswith("http://") or value.startswith("https://") or "." in value


def normalize(raw: RawBusiness, source: str) -> NormalizedBusiness:
    """Map one discovered business to the CRM fit-data shape.

    ``website_status`` is a real detection result: a usable website URL -> ``has-website``; its
    absence -> ``no-website``. ``contact_name`` is left unset — discovery rarely exposes a contact
    person, and the CRM defaults it to the business name (a documented normalization, not fabrication).
    """
    has_website = bool(raw.website and _looks_like_url(raw.website))

    return NormalizedBusiness(
        business_name=raw.name.strip(),
        contact_name=None,
        email=raw.email or None,
        phone=raw.phone or None,
        industry=raw.category or None,
        website_status="has-website" if has_website else "no-website",
        # Persist the actual URL only when it's a real website (no fabrication).
        website=raw.website.strip() if has_website and raw.website else None,
        region=raw.region or None,
        province=raw.province or None,
        city=raw.city or None,
        latitude=raw.latitude,
        longitude=raw.longitude,
        place_id=raw.provider_id or None,
        source=source,
    )
