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
    provider's native format.

    ``provider_id`` IS THE DEDUP CONTRACT. The CRM dedups discovered businesses on it (as
    ``place_id``), so it must be a STABLE NATURAL KEY the provider re-derives identically on every
    run — never a value a provider invents, and never anything a language model supplies.

    Key namespacing, for the providers that come next: OpenStreetMap's keys stay in their historical
    unprefixed form (``node/123``) because thousands of leads are already stored under it and
    re-prefixing them would make every existing lead look new — a mass-duplication event. Providers
    that resolve identity by some OTHER means namespace theirs (``wikidata/Q6818610``,
    ``web/example.com``, ``places/ChIJ...``) so cross-provider keys can never collide.

    A consequence worth stating: two providers that resolve the SAME OSM business produce the SAME
    key, and the CRM correctly treats them as one lead. That is why AI-assisted OSM discovery
    (Capability A) dedups cleanly against the plain OSM provider instead of doubling the pool.
    """

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

    # HOW this record's identity was established, and how strongly. Optional and defaulting to None
    # so the OpenStreetMap provider's output is byte-identical to before these existed.
    #
    # Present for Capability B (AI-native discovery), where a candidate has no OSM node and identity
    # must be proven some other way — its own website judged by the CRM's WebsiteIdentityVerifier, or
    # Wikidata P856. Those paths need to carry HOW they were verified so the CRM can hold them to a
    # stricter bar than an OSM lookup. Declared now, unused now: the alternative is discovering later
    # that the contract cannot express non-OSM identity and having to reopen every provider.
    #
    # These deliberately do NOT reach the CRM. NormalizedBusiness does not carry them, and both the
    # ingest FormRequest and LeadImportService::normalizeDiscovered are allowlists — so persisting
    # them would take a deliberate, separate decision on both sides.
    verification_source: Optional[str] = None       # "osm" | "wikidata" | "website" | ...
    verification_confidence: Optional[int] = None   # 0-100, when a verifier actually scored it


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
