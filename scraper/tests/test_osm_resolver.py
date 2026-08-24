"""The deterministic resolver — the trust boundary of AI-assisted discovery.

Every test here is about one question: can something a language model said become a CRM lead without
OpenStreetMap confirming it? The answer must be no, for every shape of "said".

No network: the OSM lookup is injected.
"""

from scraper.models import RawBusiness
from scraper.providers.osm_resolver import OsmNameResolver, normalize_name


def osm(name: str, provider_id: str) -> RawBusiness:
    return RawBusiness(provider_id=provider_id, name=name, latitude=14.5, longitude=121.0)


def resolver_for(*found: RawBusiness) -> OsmNameResolver:
    return OsmNameResolver(lambda names, area: list(found))


# --- the core guarantee ---------------------------------------------------------


def test_a_proposal_that_osm_confirms_resolves_with_the_real_osm_identity():
    outcome = resolver_for(osm("Kopi Roasters", "node/1")).resolve(["Kopi Roasters"], area="Makati")

    assert len(outcome.resolved) == 1
    business = outcome.resolved[0]
    # Identity is OSM's, never the proposer's.
    assert business.provider_id == "node/1"
    assert business.latitude == 14.5
    assert outcome.resolution_rate == 1.0


def test_a_proposal_osm_does_not_know_is_dropped_entirely():
    outcome = resolver_for().resolve(["Totally Invented Cafe"], area="Makati")

    assert outcome.resolved == []
    assert outcome.unmatched == ["Totally Invented Cafe"]
    assert outcome.resolution_rate == 0.0


def test_a_hallucinated_name_cannot_reach_the_pipeline_alongside_a_real_one():
    # The realistic failure: mostly-real output with one invention buried in it. The invention must
    # be dropped WITHOUT costing the real businesses their place.
    outcome = resolver_for(osm("Real Cafe", "node/1"), osm("Also Real", "node/2")).resolve(
        ["Real Cafe", "Imaginary Bistro", "Also Real"], area="Makati"
    )

    assert sorted(b.provider_id for b in outcome.resolved) == ["node/1", "node/2"]
    assert outcome.unmatched == ["Imaginary Bistro"]
    assert outcome.proposed_count == 3


def test_an_ambiguous_proposal_resolves_to_nothing_rather_than_guessing():
    # Two DIFFERENT real businesses share the name. Picking one would attach a real place_id to
    # possibly the wrong business — and because the CRM dedups on that key, the error would be
    # permanent and silent. Taking neither is the only safe answer.
    outcome = resolver_for(osm("Mercury Drug", "node/1"), osm("Mercury Drug", "node/2")).resolve(
        ["Mercury Drug"], area="Vigan"
    )

    assert outcome.resolved == []
    assert outcome.ambiguous == ["Mercury Drug"]


def test_the_same_business_returned_twice_is_not_ambiguous():
    # One business, two elements with the same key — a duplicate reading, not a conflict.
    outcome = resolver_for(osm("Kopi Roasters", "node/1"), osm("Kopi Roasters", "node/1")).resolve(
        ["Kopi Roasters"], area="Makati"
    )

    assert len(outcome.resolved) == 1
    assert outcome.ambiguous == []


# --- name matching --------------------------------------------------------------


def test_matching_ignores_case_punctuation_and_word_order():
    outcome = resolver_for(osm("Mercury Drug Vigan", "node/1")).resolve(
        ["vigan  mercury-drug"], area="Vigan"
    )

    assert len(outcome.resolved) == 1


def test_stopwords_cannot_make_two_different_businesses_look_alike():
    # "ABC Trading Inc." vs "XYZ Trading Inc." — identical once the noise words are removed, which is
    # exactly the collision the stopword list exists to prevent.
    assert normalize_name("ABC Trading Inc.") != normalize_name("XYZ Trading Inc.")

    outcome = resolver_for(osm("XYZ Trading Inc.", "node/2")).resolve(
        ["ABC Trading Inc."], area="Makati"
    )
    assert outcome.resolved == []


def test_a_proposal_made_only_of_stopwords_is_refused_before_lookup():
    # "The Company Inc." carries no identity to match on; treating it as a proposal would let it
    # match any business whose name also reduces to nothing.
    outcome = resolver_for(osm("Anything", "node/1")).resolve(["The Company Inc."], area="Makati")

    assert outcome.resolved == []
    assert outcome.proposed_count == 0


def test_duplicate_proposals_are_counted_once():
    # A model repeating itself must not inflate the resolution rate's denominator, or a chatty
    # provider would look worse than a terse one for no real reason.
    outcome = resolver_for(osm("Kopi Roasters", "node/1")).resolve(
        ["Kopi Roasters", "kopi roasters", "KOPI  ROASTERS"], area="Makati"
    )

    assert outcome.proposed_count == 1
    assert len(outcome.resolved) == 1


def test_non_string_and_empty_proposals_are_ignored():
    outcome = resolver_for(osm("Kopi Roasters", "node/1")).resolve(
        ["Kopi Roasters", "", "   ", None, 42, {"name": "x"}], area="Makati"
    )

    assert outcome.proposed_count == 1
    assert len(outcome.resolved) == 1


def test_nothing_proposed_means_no_lookup_and_no_results():
    called = []
    resolver = OsmNameResolver(lambda names, area: called.append(names) or [])

    outcome = resolver.resolve([], area="Makati")

    assert outcome.resolved == []
    assert outcome.resolution_rate == 0.0
    assert called == []  # never spend an Overpass query on an empty batch


def test_the_whole_batch_is_looked_up_in_one_call():
    # Overpass is shared community infrastructure; a query per proposal would multiply our load by
    # the proposal count. The batch must go out as one request.
    calls = []

    def lookup(names, area):
        calls.append(names)
        return [osm("Alpha Cafe", "node/1"), osm("Bravo Diner", "node/2"), osm("Charlie Bakery", "node/3")]

    names = ["Alpha Cafe", "Bravo Diner", "Charlie Bakery"]
    outcome = OsmNameResolver(lookup).resolve(names, area="Makati")

    assert len(calls) == 1
    assert calls[0] == names
    assert len(outcome.resolved) == 3
