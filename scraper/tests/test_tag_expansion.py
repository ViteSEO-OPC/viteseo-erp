"""Validation of AI-proposed OSM tag selectors — the trust boundary of tag expansion.

Every test here asks one question: can something a language model said become an executed OSM query
without being checked? The answer must be no, for every shape of "said".

Pure: no network, no CRM, no model.
"""

from scraper.providers.openstreetmap import _CATEGORY_KEYS, _NON_BUSINESS_VALUES
from scraper.providers.tag_expansion import (
    ALLOWED_KEYS,
    MAX_SELECTORS,
    REJECT_ALREADY_KNOWN,
    REJECT_KEY,
    REJECT_MALFORMED,
    REJECT_NON_BUSINESS,
    REJECT_OVER_CAP,
    REJECT_VALUE,
    selector_label,
    validate_selectors,
)


def labels(outcome) -> list[str]:
    return [selector_label(s) for s in outcome.selectors]


# --- what a usable selector looks like --------------------------------------------


def test_a_real_business_tag_is_accepted():
    outcome = validate_selectors(["amenity=fast_food"])

    assert outcome.selectors == [("amenity", "fast_food")]
    assert outcome.rejected_count == 0


def test_the_measured_restaurant_expansion_passes_intact():
    # The three selectors that produced +25 businesses in the live Vigan measurement. If validation
    # ever refuses these, the capability has lost the result that justified building it.
    outcome = validate_selectors(
        ["amenity=fast_food", "amenity=food_court", "amenity=ice_cream"],
        known=[("amenity", "restaurant")],
    )

    assert labels(outcome) == ["amenity=fast_food", "amenity=food_court", "amenity=ice_cream"]


def test_case_and_whitespace_are_normalised():
    outcome = validate_selectors(["  Amenity = Fast_Food  "])

    assert outcome.selectors == [("amenity", "fast_food")]


# --- the refusals -----------------------------------------------------------------


def test_a_car_park_selector_is_refused():
    # THE CHECK THAT MATTERS MOST. `discover()` applies no business filter to its results — it trusts
    # its selectors because a human wrote them. Tag expansion is the first path where a selector is
    # not human-written, so a swept `amenity=parking` would land every car park in town as a lead.
    outcome = validate_selectors(["amenity=parking"])

    assert outcome.selectors == []
    assert outcome.rejected[REJECT_NON_BUSINESS] == ["amenity=parking"]


def test_every_infrastructure_value_the_provider_knows_is_refused():
    # Pinned against the shared constant so a value added there is covered here automatically.
    proposals = [f"{key}={value}" for key, values in _NON_BUSINESS_VALUES.items() for value in values]

    outcome = validate_selectors(proposals)

    assert outcome.selectors == []
    assert len(outcome.rejected[REJECT_NON_BUSINESS]) == len(proposals)


def test_a_key_outside_the_allowlist_is_refused():
    # `building` and `highway` describe geography, not a business. A sweep for them would return map
    # furniture with names.
    outcome = validate_selectors(["building=yes", "highway=residential", "landuse=retail"])

    assert outcome.selectors == []
    assert len(outcome.rejected[REJECT_KEY]) == 3


def test_a_malformed_selector_is_refused():
    outcome = validate_selectors(["amenity", "amenity=a=b", "", "=", "shop="])

    assert outcome.selectors == []
    assert REJECT_MALFORMED in outcome.rejected or REJECT_VALUE in outcome.rejected
    assert outcome.rejected_count == 5


def test_a_value_that_is_not_a_tag_is_refused():
    # OSM values are lower-case, letter-initial. A sentence, a number or punctuation is a model
    # improvising rather than naming a tag.
    outcome = validate_selectors([
        "amenity=fast food", "amenity=123", "shop=Chemist!", "office=-", "craft=",
    ])

    assert outcome.selectors == []
    assert outcome.rejected_count == 5


def test_a_selector_the_baseline_already_sweeps_is_refused():
    # The expansion lane must never re-query what the default sweep just did.
    outcome = validate_selectors(
        ["amenity=pharmacy", "shop=chemist"], known=[("amenity", "pharmacy")]
    )

    assert labels(outcome) == ["shop=chemist"]
    assert outcome.rejected[REJECT_ALREADY_KNOWN] == ["amenity=pharmacy"]


def test_a_repeated_proposal_is_only_accepted_once():
    outcome = validate_selectors(["shop=chemist", "shop=chemist", "SHOP=CHEMIST"])

    assert labels(outcome) == ["shop=chemist"]
    assert len(outcome.rejected[REJECT_ALREADY_KNOWN]) == 2


def test_the_cap_bounds_the_query_and_the_blast_radius():
    proposals = [f"shop=thing_{i}" for i in range(MAX_SELECTORS + 4)]

    outcome = validate_selectors(proposals)

    assert len(outcome.selectors) == MAX_SELECTORS
    assert len(outcome.rejected[REJECT_OVER_CAP]) == 4


def test_non_strings_are_ignored_without_failing_the_batch():
    # A model that returns objects or nulls among the selectors loses those entries, not the batch.
    outcome = validate_selectors(["shop=chemist", None, 42, {"key": "value"}])  # type: ignore[list-item]

    assert labels(outcome) == ["shop=chemist"]
    assert outcome.proposed_count == 1


def test_nothing_proposed_is_not_a_failure():
    # A narrow category legitimately has no other common tags.
    outcome = validate_selectors([])

    assert outcome.selectors == []
    assert outcome.rejected_count == 0


# --- the vocabulary cannot drift --------------------------------------------------


def test_the_allowlist_is_the_providers_own_business_key_list():
    # Shared constant, not a copy. Expansion must never reach a key `parse_element` would not read
    # back — a selector whose key the parser ignores would land businesses with no derivable industry.
    assert ALLOWED_KEYS == frozenset(_CATEGORY_KEYS)


def test_the_rejection_count_is_the_sum_of_every_reason():
    outcome = validate_selectors(
        ["amenity=fast_food", "amenity=parking", "building=yes", "amenity", "amenity=Bad Value"]
    )

    assert labels(outcome) == ["amenity=fast_food"]
    assert outcome.rejected_count == 4
    assert outcome.proposed_count == 5
