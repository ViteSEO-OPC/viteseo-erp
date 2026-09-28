"""Tier 0 candidate discovery: recovering the OSM tags discovery discards, and resolving brands.

The assembly rules are pure, so these run without Overpass or Wikidata. What they pin is that Tier 0
PROPOSES and never concludes — it produces candidates and an honest record of which tiers ran, and
nothing here can mark a lead as having (or lacking) a website.
"""

from scraper.website_discovery.tier0 import (
    TIER_OSM_TAG,
    TIER_WIKIDATA,
    LeadCandidates,
    candidates_for,
    match_elements_to_leads,
    name_match_score,
    parse_place_id,
)


def lead(lead_id: str = "1", place_id: str = "node/100") -> dict:
    return {"id": lead_id, "business": "Vigan Dental", "placeId": place_id, "city": "Vigan"}


# --- place_id parsing -----------------------------------------------------------


def test_place_ids_are_parsed_into_overpass_lookups():
    assert parse_place_id("node/3450314249") == ("node", 3450314249)
    assert parse_place_id("way/267216387") == ("way", 267216387)
    assert parse_place_id("relation/42") == ("relation", 42)


def test_unusable_place_ids_are_refused_rather_than_guessed():
    # A lead from a future provider ("places/ChIJ...") or a legacy row is simply not looked up here;
    # inventing an OSM id for it would query the wrong element entirely.
    for bad in ["", "12345", "places/ChIJabc", "node/", "node/abc", "unknown/1"]:
        assert parse_place_id(bad) is None


# --- Tier 0a: the tags discovery threw away ------------------------------------


def test_a_website_tag_that_discovery_never_carried_is_recovered():
    # parse_element does read `website`, but only at ingest. A tag added to OSM afterwards leaves the
    # lead with no URL forever, because the audit only queues leads that already have one.
    result = candidates_for([lead()], {"node/100": {"website": "https://vigandental.ph"}}, {})[0]

    assert [c["url"] for c in result.candidates] == ["https://vigandental.ph"]
    assert result.candidates[0]["source"] == TIER_OSM_TAG
    assert result.candidates[0]["source_detail"] == "website"


def test_alternate_url_tags_are_read_too():
    result = candidates_for(
        [lead()], {"node/100": {"contact:website": "https://a.ph", "url": "https://b.ph"}}, {}
    )[0]

    assert {c["url"] for c in result.candidates} == {"https://a.ph", "https://b.ph"}


# --- Tier 0b: brand:wikidata -> P856 -------------------------------------------


def test_a_brand_qid_resolves_to_the_official_website():
    # The measured Mercury Drug case: the Vigan nodes carry brand:wikidata but NO website tag, and
    # Q6818610 -> P856 -> mercurydrug.com. That is where the answer was hiding all along.
    tags = {"node/100": {"brand": "Mercury Drug", "brand:wikidata": "Q6818610"}}
    result = candidates_for([lead()], tags, {"Q6818610": "https://www.mercurydrug.com/"})[0]

    assert result.candidates[0]["url"] == "https://www.mercurydrug.com/"
    assert result.candidates[0]["source"] == TIER_WIKIDATA
    # Provenance a reviewer can act on: which entity, and which brand it named.
    assert "Q6818610" in result.candidates[0]["source_detail"]
    assert "Mercury Drug" in result.candidates[0]["source_detail"]


def test_a_brand_with_no_p856_contributes_nothing():
    # No fallback, no guess. "This tier found nothing" is the honest result, and it is precisely the
    # evidence an exhaustion claim is built from.
    tags = {"node/100": {"brand": "Local Chain", "brand:wikidata": "Q999"}}
    result = candidates_for([lead()], tags, {})[0]

    assert result.candidates == []
    assert result.exhausted is True


def test_both_tiers_can_contribute_for_one_lead():
    tags = {"node/100": {"website": "https://branch.ph", "brand:wikidata": "Q1"}}
    result = candidates_for([lead()], tags, {"Q1": "https://brand.ph"})[0]

    # Two independent candidates; the CRM adjudicates which (if either) is really this business's.
    assert {c["source"] for c in result.candidates} == {TIER_OSM_TAG, TIER_WIKIDATA}
    assert len(result.candidates) == 2


# --- honesty of the record ------------------------------------------------------


def test_both_tiers_are_reported_as_run_even_when_they_find_nothing():
    # Claiming a tier was skipped when it was attempted-and-empty would understate the search and
    # make a later exhaustion claim look weaker than it is.
    result = candidates_for([lead()], {}, {})[0]

    assert result.tiers_run == [TIER_OSM_TAG, TIER_WIKIDATA]
    assert result.exhausted is True


def test_a_lead_whose_element_could_not_be_fetched_is_not_silently_skipped():
    # Overpass returning nothing for a place_id must still produce a record — otherwise the lead
    # would look never-searched and be re-queued forever.
    results = candidates_for([lead("7", "node/999")], {}, {})

    assert len(results) == 1
    assert results[0].lead_id == "7"
    assert results[0].tiers_run == [TIER_OSM_TAG, TIER_WIKIDATA]


def test_the_same_url_is_not_duplicated_within_one_source():
    tags = {"node/100": {"website": "https://a.ph", "contact:website": "https://a.ph"}}
    result = candidates_for([lead()], tags, {})[0]

    assert len(result.candidates) == 1


def test_exhausted_means_no_candidates_not_a_verdict():
    # The dataclass reports a CLAIM the CRM independently re-checks. It is not a status, and nothing
    # in this package can write one.
    assert LeadCandidates(lead_id="1").exhausted is True
    assert LeadCandidates(lead_id="1", candidates=[{"url": "x"}]).exhausted is False


def test_every_lead_in_the_batch_gets_a_result():
    leads = [lead("1", "node/1"), lead("2", "node/2"), lead("3", "node/3")]
    results = candidates_for(leads, {"node/2": {"website": "https://b.ph"}}, {})

    assert [r.lead_id for r in results] == ["1", "2", "3"]
    assert [len(r.candidates) for r in results] == [0, 1, 0]


# --- a tier is only "run" where it could actually look ---------------------------------------------


def test_osm_tiers_are_not_reported_as_run_for_a_google_place_id():
    """THE EXHAUSTION HONESTY RULE.

    Both tiers here resolve through an OSM element id. A Google Places lead carries
    ``places/ChIJ…``, which this resolver cannot parse — so there is no element to read a ``website``
    tag from and no ``brand:wikidata`` to follow. Reporting them as run would tell the CRM that two
    independent tiers found nothing, and the CRM builds its exhaustion claim on exactly that list:
    with every required tier reported, a search with no candidates resolves itself to ``no-website``
    and the lead becomes High priority. Measured on the live pool, that would have asserted "this
    business has no website" for hundreds of Google leads on the strength of one DNS probe.
    """
    leads = [{"id": "1", "placeId": "places/ChIJ_anF5hvJlzMR9ku"}]
    result = candidates_for(leads, {}, {})[0]

    assert result.tiers_run == []
    # Still exhausted in the LOCAL sense — this tier found nothing — but the CRM will refuse to call
    # that absence, because the tiers it requires were never reported.
    assert result.exhausted is True


def test_osm_tiers_are_still_reported_as_run_when_the_element_simply_had_no_website():
    """ATTEMPTED-AND-EMPTY IS STILL RUN. An element with no ``website`` tag is a real negative
    answer, and claiming the tier was skipped would understate that search."""
    leads = [{"id": "2", "placeId": "node/12099865676"}]
    result = candidates_for(leads, {"node/12099865676": {"amenity": "cafe"}}, {})[0]

    assert result.tiers_run == [TIER_OSM_TAG, TIER_WIKIDATA]
    assert result.candidates == []


def test_an_osm_lead_with_a_website_tag_still_yields_its_candidate():
    """The working path is untouched."""
    leads = [{"id": "3", "placeId": "way/555"}]
    result = candidates_for(leads, {"way/555": {"website": "https://example.ph"}}, {})[0]

    assert [c["url"] for c in result.candidates] == ["https://example.ph"]
    assert result.candidates[0]["source"] == TIER_OSM_TAG
    assert result.exhausted is False


# --- location resolution: giving Tier 0 somewhere to look for a Google lead -------------------------
#
# The Google pool's `places/ChIJ…` is not an OSM id and cannot be converted into one, so Tier 0 had
# nowhere to look for every Google lead and `tiers_run` was honestly empty forever. These pin the
# second way to reach the same element — the lead's own coordinates and name — and, just as hard,
# every case where that must NOT produce a match.


def google_lead(lead_id="1", name="Aling Nena Bakery", lat=14.5995, lon=120.9842,
                place_id="places/ChIJ_anF5hvJlzMR9ku") -> dict:
    return {"id": lead_id, "business": name, "placeId": place_id,
            "latitude": lat, "longitude": lon}


def osm_element(element_id=100, name="Aling Nena Bakery", lat=14.5995, lon=120.9842,
                kind="node", **tags) -> dict:
    return {"type": kind, "id": element_id, "lat": lat, "lon": lon,
            "tags": {"name": name, **tags}}


def test_a_google_lead_matches_a_real_element_by_coordinates_and_name():
    resolved = match_elements_to_leads([google_lead()], [osm_element()])

    assert resolved["places/ChIJ_anF5hvJlzMR9ku"]["osm_id"] == "node/100"
    assert resolved["places/ChIJ_anF5hvJlzMR9ku"]["distance_m"] == 0.0


def test_a_matched_element_makes_tier_0_genuinely_runnable():
    """THE WHOLE POINT. With an element resolved, both tiers can look — so both are reported, and
    the CRM's REQUIRED_TIERS can finally be satisfied by a search that actually happened."""
    leads = [google_lead()]
    resolved = match_elements_to_leads(leads, [osm_element()])
    result = candidates_for(
        leads,
        {"places/ChIJ_anF5hvJlzMR9ku": resolved["places/ChIJ_anF5hvJlzMR9ku"]["tags"]},
        {},
        {"places/ChIJ_anF5hvJlzMR9ku": "node/100"},
    )[0]

    assert result.tiers_run == [TIER_OSM_TAG, TIER_WIKIDATA]
    assert result.candidates == []          # the element had no website tag — a real negative answer


def test_a_matched_element_yields_its_website_tag_with_the_element_named():
    leads = [google_lead()]
    result = candidates_for(
        leads,
        {"places/ChIJ_anF5hvJlzMR9ku": {"name": "Aling Nena Bakery", "website": "https://nena.ph"}},
        {},
        {"places/ChIJ_anF5hvJlzMR9ku": "node/100"},
    )[0]

    assert [c["url"] for c in result.candidates] == ["https://nena.ph"]
    # Provenance names the matched element, so a reviewer can check the match instead of trusting it.
    assert result.candidates[0]["source_detail"] == "website via node/100"


def test_no_element_within_the_radius_is_not_a_match():
    """~1.1 km away. Tier 0 stays unrun and the lead stays un-exhausted — the honest outcome."""
    far = osm_element(lat=14.6095, lon=120.9842)

    assert match_elements_to_leads([google_lead()], [far]) == {}


def test_a_nearby_business_with_a_different_name_is_not_a_match():
    """COORDINATES ARE NOT IDENTITY. At 120 m in a commercial district there are many businesses;
    the name is what decides which of them this is."""
    neighbour = osm_element(name="Mang Tomas Hardware")

    assert match_elements_to_leads([google_lead()], [neighbour]) == {}


def test_a_shared_generic_word_alone_is_not_a_match():
    """Two unrelated businesses both being 'Inc' is not evidence they are the same one."""
    lead_row = google_lead(name="Silver Oak Consultancy Inc")
    other = osm_element(name="Golden Pacific Realty Inc")

    assert match_elements_to_leads([lead_row], [other]) == {}


def test_a_more_specific_osm_name_still_matches():
    """OSM is routinely more specific than the Google record — the same restaurant, named longer."""
    lead_row = google_lead(name="Jollibee")
    branch = osm_element(name="Jollibee SM North EDSA")

    assert match_elements_to_leads([lead_row], [branch])["places/ChIJ_anF5hvJlzMR9ku"]["score"] == 1.0


def test_one_element_is_never_credited_to_two_leads():
    """Two different businesses cannot be the same OSM element. Without this, same-named branches
    would each be credited with the other's evidence."""
    a = google_lead("1", lat=14.59950, lon=120.98420)
    b = google_lead("2", lat=14.59951, lon=120.98421, place_id="places/ChIJ_second")
    resolved = match_elements_to_leads([a, b], [osm_element()])

    assert len(resolved) == 1


def test_a_lead_without_coordinates_cannot_be_matched():
    without = {"id": "9", "business": "Aling Nena Bakery", "placeId": "places/ChIJ_x",
               "latitude": None, "longitude": None}

    assert match_elements_to_leads([without], [osm_element()]) == {}


def test_an_element_without_a_position_is_ignored_rather_than_assumed():
    positionless = {"type": "way", "id": 7, "tags": {"name": "Aling Nena Bakery"}}

    assert match_elements_to_leads([google_lead()], [positionless]) == {}


def test_a_way_is_matched_through_its_centre():
    way = {"type": "way", "id": 42, "center": {"lat": 14.5995, "lon": 120.9842},
           "tags": {"name": "Aling Nena Bakery"}}

    assert match_elements_to_leads([google_lead()], [way])["places/ChIJ_anF5hvJlzMR9ku"]["osm_id"] == "way/42"


def test_an_unmatched_google_lead_keeps_tier_0_unrun():
    """P2, restated against the new path: the resolver widens where we can look, never what we may
    claim. No element resolved, no tiers reported, no exhaustion."""
    result = candidates_for([google_lead()], {}, {}, {})[0]

    assert result.tiers_run == []
    assert result.exhausted is True


def test_matching_is_case_and_accent_insensitive():
    lead_row = google_lead(name="CAFÉ ADRIÁTICO")
    element = osm_element(name="Cafe Adriatico")

    assert match_elements_to_leads([lead_row], [element]) != {}


def test_the_score_measures_the_lead_name_not_the_element_name():
    """Asymmetric on purpose: the LEAD's words are what must be accounted for, and an element that
    adds detail is still the same business."""
    assert name_match_score("Mercury Drug Ayala", "Mercury Drug") == 2 / 3
    assert name_match_score("Mercury Drug", "Mercury Drug Ayala Center Cebu") == 1.0
    # Nothing distinctive on either side is nothing to corroborate.
    assert name_match_score("Inc", "Inc") == 0.0
