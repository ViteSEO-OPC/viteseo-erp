"""Normalizer tests — RawBusiness -> CRM fit-data, and the ingest payload's data-provider guarantees."""

from scraper.models import NormalizedBusiness, RawBusiness
from scraper.normalizer import normalize
from scraper.providers.openstreetmap import parse_element

# Engagement / CRM-decision fields the scraper must NEVER emit.
FORBIDDEN_FIELDS = {"priority", "status", "email_outreach", "cold_calling", "score", "assigned_to"}


def test_normalize_real_element_end_to_end(overpass_element):
    raw = parse_element(overpass_element)
    assert raw is not None

    business = normalize(raw, source="openstreetmap")

    assert business.business_name == "Starbucks"
    # A URL in the provider record is the AUDIT'S INPUT, not a verdict — discovery never claims that
    # a business has a website, only that a directory happened to list one.
    assert business.website_status == "website-unknown"
    assert business.website == "https://www.starbucks.com/"
    assert business.industry == "Cafe"
    assert business.city == "Makati"
    assert business.place_id == "node/255068024"
    assert business.source == "openstreetmap"
    # Discovery exposes no contact person -> left unset (CRM defaults it to the business name).
    assert business.contact_name is None


def test_normalize_missing_website_is_unknown_not_no_website():
    """The Mercury Drug defect: OpenStreetMap omits the `website` tag constantly, including for chains
    with obvious web presences. That silence is a gap in the DIRECTORY, and recording it as
    `no-website` told the CRM a business had no website and handed it the +25 greenfield-prospect
    signal on no evidence at all."""
    raw = RawBusiness(provider_id="node/1", name="Sari-Sari Store", website=None)
    business = normalize(raw, source="openstreetmap")

    assert business.website_status == "website-unknown"
    assert business.website is None


def test_normalize_non_url_website_is_unknown_and_carries_no_url():
    raw = RawBusiness(provider_id="node/2", name="Nook", website="see facebook")
    business = normalize(raw, source="openstreetmap")

    assert business.website_status == "website-unknown"
    assert business.website is None, "an unusable value is never persisted as a URL"


def test_the_provider_shape_cannot_express_a_website_claim():
    """Structural, not conventional: `has-website` is not in the provider's vocabulary, so no future
    edit to the normalizer can reintroduce the guess without this failing first."""
    import pytest
    from pydantic import ValidationError

    for claimed in ("has-website", "no-website", "needs-redesign"):
        with pytest.raises(ValidationError):
            NormalizedBusiness(business_name="X", source="openstreetmap", website_status=claimed)


def test_payload_excludes_none_and_forbidden_fields():
    business = NormalizedBusiness(business_name="Acme", source="openstreetmap")
    payload = business.to_payload()

    # None fit-data fields are dropped so CRM defaults apply.
    assert payload == {"business_name": "Acme", "source": "openstreetmap"}

    # The shape can never carry engagement / decision fields — the scraper is a data provider.
    assert FORBIDDEN_FIELDS.isdisjoint(payload.keys())


def test_payload_never_contains_forbidden_fields_when_fully_populated():
    business = NormalizedBusiness(
        business_name="Full Co",
        email="a@b.com",
        phone="+63999",
        industry="Cafe",
        website_status="website-unknown",
        region="Metro Manila",
        province="Southern Manila District",
        city="Makati",
        latitude=14.5,
        longitude=121.0,
        place_id="node/9",
        source="openstreetmap",
    )
    assert FORBIDDEN_FIELDS.isdisjoint(business.to_payload().keys())
