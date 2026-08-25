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


# --- the name query: prefix-anchored, not exact -----------------------------------


def _name_query(*names: str) -> str:
    """The Overpass query the provider builds for a batch of proposed names, without a network."""
    from scraper.providers.openstreetmap import OpenStreetMapProvider

    provider = OpenStreetMapProvider.__new__(OpenStreetMapProvider)
    captured = {}

    def capture(query):
        captured["query"] = query
        raise AssertionError("network must not be reached")

    provider._post_with_retry = capture  # type: ignore[method-assign]
    try:
        provider._overpass_by_name(list(names), (1.0, 2.0, 3.0, 4.0), 200)
    except AssertionError:
        pass
    return captured["query"]


def test_the_name_query_is_anchored_at_the_start_only():
    # THE GATE THAT MADE BRANCH NAMES UNREACHABLE. With a trailing `$`, "Mercury Drug" could not
    # retrieve "Mercury Drug Vigan" — the element was never fetched, so the resolver never got to
    # judge it. Dropping the trailing anchor is what lets real Philippine branch rows be considered.
    query = _name_query("Mercury Drug")

    assert "^(Mercury" in query
    assert ")$" not in query


def test_the_leading_anchor_survives_so_a_generic_word_cannot_sweep():
    # The safety half of the asymmetry: widening happens only in the direction real names vary —
    # extra words at the END. "Drug" must still not match "Mercury Drug".
    import re

    query = _name_query("Drug")
    pattern = re.search(r'\["name"~"(.+?)",i\]', query).group(1).replace("\\\\", "\\")

    assert pattern.startswith("^(")
    assert re.match(pattern, "Drug Store", re.I)
    assert not re.match(pattern, "Mercury Drug", re.I)


def test_a_whole_batch_of_names_is_still_one_query():
    # Overpass is shared community infrastructure; a query per name would multiply the load by the
    # proposal count.
    query = _name_query("Mercury Drug", "Rose Pharmacy", "Vigan Dental")

    assert query.count("node[") == 1
    assert query.count("way[") == 1


def test_regex_metacharacters_in_a_name_are_still_escaped():
    # "A+B Trading" must not compile into a quantifier.
    query = _name_query("A+B Trading")

    assert "A\\+B" in query or "A\\\\+B" in query


# --- name lookup must not return map features ------------------------------------


def test_a_car_park_named_after_a_business_is_not_a_business():
    # FOUND BY MEASUREMENT, not by reasoning: a live run returned "Grandpa's Inn and Restaurant
    # Customer Parking" as a candidate lead. Prefix matching lets a real business's name reach nearby
    # features that merely quote it, and a car park with a real place_id would be a permanent wrong row.
    from scraper.providers.openstreetmap import is_business_element

    assert not is_business_element({
        "type": "way", "id": 470500871,
        "tags": {"name": "Grandpa's Inn and Restaurant Customer Parking", "amenity": "parking"},
    })


def test_a_real_business_sharing_a_chain_name_still_passes():
    # The filter refuses infrastructure, not businesses in an unexpected category. A gift shop found
    # while looking for restaurants is a real prospect; judging it is the CRM's job.
    from scraper.providers.openstreetmap import is_business_element

    assert is_business_element({
        "type": "node", "id": 1,
        "tags": {"name": "Grandpa's Inn Souvenir and Gift Shop", "shop": "gift"},
    })
    assert is_business_element({
        "type": "node", "id": 2, "tags": {"name": "Mercury Drug Vigan", "amenity": "pharmacy"},
    })


def test_an_untagged_named_element_is_not_refused():
    # The filter refuses only what is positively identified as infrastructure. A business whose only
    # distinguishing tag is its name is exactly what name lookup exists to find.
    from scraper.providers.openstreetmap import is_business_element

    assert is_business_element({"type": "node", "id": 3, "tags": {"name": "Kusina Felicitas"}})


def test_the_default_sweep_is_not_subject_to_the_filter():
    # `discover()` names the tags it wants, so it cannot return infrastructure and must not gain a
    # filter — the CEO-accepted default behaviour stays byte-identical.
    import inspect

    from scraper.providers.openstreetmap import OpenStreetMapProvider

    assert "is_business_element" not in inspect.getsource(OpenStreetMapProvider.discover)


# --- selector sweep: the expansion lane's only OSM call ---------------------------


class _SweepProvider:
    """A provider whose Overpass call is replaced by fixed elements — no network, no geocode."""

    def __init__(self, elements):
        from scraper.providers.openstreetmap import OpenStreetMapProvider

        self.p = OpenStreetMapProvider.__new__(OpenStreetMapProvider)
        self.p.reverse_geocode = False
        self.p._geocode_area = lambda area: (1.0, 2.0, 3.0, 4.0)  # type: ignore[method-assign]
        self.p._overpass = lambda selectors, bbox, limit: elements  # type: ignore[method-assign]


def test_a_selector_sweep_attributes_each_business_to_the_tag_it_carries():
    # Attribution comes from the ELEMENT'S OWN TAGS, not from the query — one Overpass call unions
    # every selector, so the response cannot say which one matched what.
    elements = [
        {"type": "node", "id": 1, "lat": 1.0, "lon": 2.0,
         "tags": {"name": "Mang Inasal", "amenity": "fast_food"}},
        {"type": "node", "id": 2, "lat": 1.0, "lon": 2.0,
         "tags": {"name": "Vigan Food Hall", "amenity": "food_court"}},
    ]
    sweep = _SweepProvider(elements).p.find_by_selectors(
        [("amenity", "fast_food"), ("amenity", "food_court")], area="Vigan"
    )

    assert [b.provider_id for b in sweep.businesses] == ["node/1", "node/2"]
    assert sweep.by_selector == {
        "amenity=fast_food": ["node/1"],
        "amenity=food_court": ["node/2"],
    }


def test_a_business_carrying_two_matched_tags_is_attributed_to_both():
    # Which is why per-selector net-new may sum to more than the lane total: the lane deduplicates,
    # and each selector's figure answers "what would we lose by dropping this one?".
    elements = [{"type": "node", "id": 1, "lat": 1.0, "lon": 2.0,
                 "tags": {"name": "Rose Pharmacy", "amenity": "pharmacy", "healthcare": "pharmacy"}}]
    sweep = _SweepProvider(elements).p.find_by_selectors(
        [("amenity", "pharmacy"), ("healthcare", "pharmacy")], area="Vigan"
    )

    assert len(sweep.businesses) == 1
    assert sweep.by_selector == {
        "amenity=pharmacy": ["node/1"],
        "healthcare=pharmacy": ["node/1"],
    }


def test_an_empty_selector_list_makes_no_query_at_all():
    # No selectors survived validation. That must cost nothing — not a geocode, not an Overpass call.
    from scraper.providers.openstreetmap import OpenStreetMapProvider

    provider = OpenStreetMapProvider.__new__(OpenStreetMapProvider)

    def explode(*a, **k):
        raise AssertionError("no network may be reached")

    provider._geocode_area = explode  # type: ignore[method-assign]
    provider._overpass = explode  # type: ignore[method-assign]

    sweep = provider.find_by_selectors([], area="Vigan")

    assert sweep.businesses == []
    assert sweep.by_selector == {}


def test_a_selector_sweep_stamps_osm_as_the_verification_source():
    elements = [{"type": "node", "id": 1, "lat": 1.0, "lon": 2.0,
                 "tags": {"name": "Mang Inasal", "amenity": "fast_food"}}]
    sweep = _SweepProvider(elements).p.find_by_selectors([("amenity", "fast_food")], area="Vigan")

    assert sweep.businesses[0].verification_source == "osm"


def test_the_default_sweep_does_not_know_about_selector_sweeps():
    # `discover()` is the CEO-accepted default and must stay byte-identical. Expansion is a SEPARATE
    # call the composite makes, never a widening of this one.
    import inspect

    from scraper.providers.openstreetmap import OpenStreetMapProvider

    source = inspect.getsource(OpenStreetMapProvider.discover)
    assert "find_by_selectors" not in source
    assert "SelectorSweep" not in source

