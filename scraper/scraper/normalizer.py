"""Normalizer — provider-neutral ``RawBusiness`` -> canonical ``NormalizedBusiness`` (CRM fit-data).

This is where discovery output becomes the exact shape the CRM ingest accepts. It captures the URL a
provider supplied (when it supplied one) and passes through identity, contactability, category,
administrative location, coordinates, and provenance. It computes no score, KPI, ownership, or
engagement value — those are not part of the shape.

It also does not decide whether a business HAS a website. Discovery can only observe whether a
directory record happens to carry a URL tag, which is a fact about the directory, not about the
business — OpenStreetMap omits the tag routinely, including for chains with obvious web presences.
That observation is not evidence, so every discovered business leaves here as ``website-unknown`` and
the question is settled by the Website Intelligence audit, which fetches the URL and verifies the page
actually belongs to the business.
"""

from __future__ import annotations

import re

from scraper.models import NormalizedBusiness, ProvenanceOnlyRecord, RawBusiness

#: The OpenStreetMap element key — the only identity a provenance-only record may carry. The CRM
#: validates the same shape, so a Google place id can never travel on this path.
_OSM_ID = re.compile(r"^(node|way|relation)/[1-9][0-9]{0,18}$")


def _looks_like_url(value: str) -> bool:
    value = value.strip().lower()
    return value.startswith("http://") or value.startswith("https://") or "." in value


def normalize(raw: RawBusiness, source: str) -> NormalizedBusiness:
    """Map one discovered business to the CRM fit-data shape.

    ``website_status`` is always ``website-unknown`` — see the module docstring. The URL itself IS
    carried when the provider supplied a usable one; it is the audit's input, not a verdict.
    ``contact_name`` is left unset — discovery rarely exposes a contact person, and the CRM defaults it
    to the business name (a documented normalization, not fabrication).

    ``source`` PREFERS THE RECORD'S OWN LANE over the run's provider name. A composite run has two
    lanes — the OpenStreetMap tag sweep and AI-assisted expansion — and labelling both with a single
    run-level name would make it impossible to tell afterwards whether the AI leg contributed
    anything. A record that does not name its own lane falls back to ``source``, so every existing
    single-provider caller behaves exactly as it did before.
    """
    has_url = bool(raw.website and _looks_like_url(raw.website))

    return NormalizedBusiness(
        business_name=raw.name.strip(),
        contact_name=None,
        email=raw.email or None,
        phone=raw.phone or None,
        industry=raw.category or None,
        website_status="website-unknown",
        # Persist the actual URL only when the provider supplied a usable one (no fabrication).
        website=raw.website.strip() if has_url and raw.website else None,
        region=raw.region or None,
        province=raw.province or None,
        city=raw.city or None,
        latitude=raw.latitude,
        longitude=raw.longitude,
        place_id=raw.provider_id or None,
        source=raw.discovered_by or source,
        # Passed straight through, including False. `or` would be wrong here: it collapses a
        # recorded False into None and loses the distinction the field exists to carry.
        discovery_ai_assisted=raw.ai_assisted,
        # Sorted and de-duplicated; None when empty so a plain record's payload is unchanged.
        discovery_also_found_by=sorted(set(raw.also_found_by)) or None,
    )


def normalize_provenance(raw: RawBusiness, lane: str) -> ProvenanceOnlyRecord | None:
    """Reduce an AI finding the result limit dropped to a provenance-only record.

    ``None`` when the record cannot be one: no OSM identity to match an existing lead on, or no
    position for the CRM's national boundary to check. Dropping it loses nothing the ordinary path
    would have kept — the record was already outside the result.
    """
    if not raw.provider_id or not _OSM_ID.match(raw.provider_id):
        return None
    if raw.latitude is None or raw.longitude is None:
        return None
    return ProvenanceOnlyRecord(
        place_id=raw.provider_id, lane=lane, latitude=raw.latitude, longitude=raw.longitude
    )
