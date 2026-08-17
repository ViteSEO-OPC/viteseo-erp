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
    assert business.website_status == "has-website"  # real website detected
    assert business.industry == "Cafe"
    assert business.city == "Makati"
    assert business.place_id == "node/255068024"
    assert business.source == "openstreetmap"
    # Discovery exposes no contact person -> left unset (CRM defaults it to the business name).
    assert business.contact_name is None


def test_normalize_no_website_is_no_website():
    raw = RawBusiness(provider_id="node/1", name="Sari-Sari Store", website=None)
    assert normalize(raw, source="openstreetmap").website_status == "no-website"


def test_normalize_non_url_website_is_no_website():
    raw = RawBusiness(provider_id="node/2", name="Nook", website="see facebook")
    assert normalize(raw, source="openstreetmap").website_status == "no-website"


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
        website_status="has-website",
        region="Metro Manila",
        province="Southern Manila District",
        city="Makati",
        latitude=14.5,
        longitude=121.0,
        place_id="node/9",
        source="openstreetmap",
    )
    assert FORBIDDEN_FIELDS.isdisjoint(business.to_payload().keys())
