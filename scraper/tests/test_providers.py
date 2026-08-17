"""Provider-layer tests against real, recorded OSM responses (no network)."""

import pytest

from scraper.providers.openstreetmap import (
    category_selectors,
    extract_admin_area,
    parse_element,
)


def test_parse_element_maps_real_overpass_node(overpass_element):
    business = parse_element(overpass_element)

    assert business is not None
    assert business.provider_id == "node/255068024"
    assert business.name == "Starbucks"
    assert business.website == "https://www.starbucks.com/"
    assert business.category == "Cafe"  # amenity=cafe, humanized
    assert business.city == "Makati"  # from addr:city
    assert business.latitude == pytest.approx(14.563184)
    assert business.longitude == pytest.approx(121.0373402)


def test_parse_element_uses_way_center_when_no_node_latlon():
    element = {
        "type": "way",
        "id": 42,
        "center": {"lat": 14.5, "lon": 121.0},
        "tags": {"name": "Corner Diner", "amenity": "restaurant"},
    }
    business = parse_element(element)

    assert business is not None
    assert business.provider_id == "way/42"
    assert business.latitude == 14.5
    assert business.longitude == 121.0
    assert business.category == "Restaurant"


def test_parse_element_skips_unnamed():
    assert parse_element({"type": "node", "id": 1, "tags": {"amenity": "cafe"}}) is None
    assert parse_element({"type": "node", "id": 2, "tags": {}}) is None


def test_extract_admin_area_from_real_nominatim(nominatim_reverse):
    region, province, city = extract_admin_area(nominatim_reverse["address"])

    assert region == "Metro Manila"
    assert province == "Southern Manila District"  # state_district fallback
    assert city == "Makati"


def test_category_selectors_friendly_and_raw():
    assert category_selectors("cafe") == [("amenity", "cafe")]
    assert category_selectors("amenity=restaurant") == [("amenity", "restaurant")]
    assert category_selectors("salon") == [("shop", "hairdresser"), ("shop", "beauty")]


def test_category_selectors_unknown_raises():
    with pytest.raises(ValueError):
        category_selectors("definitely-not-a-category")
