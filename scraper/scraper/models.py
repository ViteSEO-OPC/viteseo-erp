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

    The composite provider relies on exactly that property: it merges its two lanes on this key
    alone, so a business the tag sweep already found cannot be counted twice just because the AI leg
    named it too.
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

    # WHICH LANE OF A COMPOSITE RUN PRODUCED THIS RECORD, and unlike the two fields above this one
    # DOES reach the CRM — the normalizer uses it as `source` when it is set.
    #
    # It exists because a composite run has two lanes with different trust stories: the OpenStreetMap
    # tag sweep, and AI-assisted expansion. Stamping one provider name across the whole run would
    # make it impossible to answer the only question that justifies the AI leg — "did it add coverage
    # the baseline did not already have?" — because every lead would be labelled identically.
    #
    # Optional and defaulting to None so a plain OpenStreetMap run is byte-identical to before: the
    # normalizer falls back to the run's provider name whenever this is unset, so nothing that does
    # not set it can observe that it exists.
    discovered_by: Optional[str] = None

    # WHETHER AN AI-PLANNED SEARCH FOUND THIS RECORD — a SECOND, independent dimension beside
    # `discovered_by`.
    #
    # It is separate precisely because `discovered_by` becomes `source`, and `source` answers "which
    # factual provider returned this business". AI planning is not a provider: a Google Places lead
    # reached through an AI-phrased query is still a Google Places lead, and relabelling its source
    # would both lose that fact and silently change what every existing `source` consumer sees.
    # Carrying the two apart is what lets a lead say "AI-assisted AND Google Places" at once.
    #
    # Tri-state on purpose. None = the discovering lane does not record this (or predates the
    # field); False = recorded, and AI did not shape the search; True = recorded, and it did.
    ai_assisted: Optional[bool] = None

    # OTHER LANES THAT FOUND THIS SAME BUSINESS IN THE SAME SEARCH. When the baseline sweep and an AI
    # lane return one OSM element, the composite keeps the baseline's record — so `discovered_by`, and
    # therefore the lead's primary `source`, is unchanged — and names the AI lane here instead of
    # dropping its finding. It never becomes `source`; the CRM records it as additional provenance.
    # Empty for every record no second lane found, so such a payload is byte-identical to before.
    also_found_by: list[str] = Field(default_factory=list)


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
    # The AI dimension, carried BESIDE `source` rather than inside it — see `RawBusiness.ai_assisted`.
    # Named for the CRM column it lands in, because this class is the wire contract.
    #
    # `to_payload()` drops None but KEEPS False, which is exactly the tri-state this needs: a lane
    # that does not record the fact sends nothing and the column stays NULL, while a Google search
    # that ran without AI sends an explicit `false`.
    discovery_ai_assisted: Optional[bool] = None
    # Additional discovery provenance (see `RawBusiness.also_found_by`). None — and therefore absent
    # from the payload — unless another lane found the same business in the same search.
    discovery_also_found_by: Optional[list[str]] = None

    def to_payload(self) -> dict:
        """Ingest payload — drop ``None`` fields so the CRM applies its column defaults."""
        return self.model_dump(exclude_none=True)
