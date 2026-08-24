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
