"""Data models for the Lead Enrichment scraper.

Two shapes, deliberately separate:

* ``RawBusiness`` — provider-neutral discovery output. Every provider (OpenStreetMap today; Google
  Places or others later) maps its native response into this shape, so the normalizer never sees
  provider-specific JSON.
* ``NormalizedBusiness`` — the canonical CRM fit-data shape submitted to the enrichment ingest
  endpoint. It contains *only* fit-data (identity, contactability, website presence, administrative
  location, coordinates, provenance) — never Lead Score, KPI, ownership, or engagement fields.
"""

from __future__ import annotations

from typing import Literal, Optional

from pydantic import BaseModel, Field

# Discovery can only ever report "not checked". Observing that a directory record carries a URL tag
# is a fact about the directory, not about the business, so this provider never claims a website
# state — the CRM's Website Intelligence audit fetches the URL and verifies the page belongs to the
# business before any other value is assigned. The richer vocabulary (has-website / needs-redesign /
# no-website / website-unreachable) is the CRM's to write, and is deliberately not modelled here.
WebsiteStatus = Literal["website-unknown"]


class RawBusiness(BaseModel):
    """Provider-neutral discovery result. Providers populate this; nothing downstream knows the
    provider's native format."""

    provider_id: str = Field(description="Provider's stable natural key, e.g. OSM 'node/123'.")
    name: str
    latitude: Optional[float] = None
    longitude: Optional[float] = None
    website: Optional[str] = None
    phone: Optional[str] = None
    email: Optional[str] = None
    category: Optional[str] = None
    # Administrative address components already resolved by the provider (region/province/city).
    region: Optional[str] = None
    province: Optional[str] = None
    city: Optional[str] = None


class NormalizedBusiness(BaseModel):
    """Canonical CRM fit-data record. Serialized to the `/leads/discovered` ingest payload.

    The field set mirrors the backend `IngestDiscoveredLeadsRequest` allowlist exactly — engagement
    / CRM-decision fields are intentionally absent, so the scraper can never set them.
    """

    business_name: str
    contact_name: Optional[str] = None
    email: Optional[str] = None
    phone: Optional[str] = None
    industry: Optional[str] = None
    website_status: Optional[WebsiteStatus] = None
    # The website URL itself (fit-data) — persisted so the Website Intelligence service can audit it.
    website: Optional[str] = None
    region: Optional[str] = None
    province: Optional[str] = None
    city: Optional[str] = None
    latitude: Optional[float] = None
    longitude: Optional[float] = None
    place_id: Optional[str] = None
    source: str

    def to_payload(self) -> dict:
        """Ingest payload — drop ``None`` fields so the CRM applies its column defaults."""
        return self.model_dump(exclude_none=True)
