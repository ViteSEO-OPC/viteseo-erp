"""Tier 1 domain probing: deriving hostnames from a business name, and refusing to over-guess.

The assembly rules are pure, so these run without DNS. What they pin is that Tier 1 PROPOSES and
never concludes — it produces candidates the CRM's unmodified verifier still has to accept, and
nothing here can mark a lead as having (or lacking) a website.
"""

from scraper.website_discovery.tier1 import (
    MAX_HOSTS_PER_LEAD,
    TIER_PROBE,
    candidate_hosts,
    candidates_for,
    hosts_for_leads,
    is_wildcard_answer,
    resolve_hosts,
    significant_words,
    slug_variants,
    tld_of,
    wildcard_addresses,
)

#: What `.ph` and `.com.ph` actually return for any nonexistent label (measured against both the
#: system resolver and 8.8.8.8).
PH_WILDCARD = "45.79.222.138"


def fake_resolver(table: dict[str, set[str]]):
    """A resolver over a fixed table, so wildcard handling tests without touching DNS.

    Any host not in the table does not resolve — except under a TLD the table marks as wildcarded,
    which is what makes these tests reproduce the real registry behaviour rather than describe it.
    """
    wildcarded = {tld for tld in (".ph", ".com.ph") if f"WILDCARD{tld}" in table}

    def resolve(host: str) -> set[str]:
        if host in table:
            return table[host]
        tld = tld_of(host)
        if tld in wildcarded:
            return table[f"WILDCARD{tld}"]
        return set()

    return resolve


def lead(lead_id: str = "1", business: str = "Casa Verde Bistro") -> dict:
    return {"id": lead_id, "business": business, "placeId": "node/100", "city": "Vigan"}


# --- name normalisation ---------------------------------------------------------


def test_legal_wrappers_are_not_part_of_the_identity():
    # "Casa Verde Bistro PLC" and "Casa Verde Bistro" are the same business, and a domain is
    # registered against the name people use, not the one on the SEC filing.
    assert significant_words("Casa Verde Bistro PLC") == ["casa", "verde", "bistro"]
    assert significant_words("North Star Logistics and Sons") == ["north", "star", "logistics", "and"]


def test_accents_and_ampersands_fold_into_something_registrable():
    assert significant_words("Café Niño & Sons") == ["cafe", "nino", "and"]


def test_a_name_that_is_only_a_legal_wrapper_keeps_its_words():
    # Stripping everything would silently skip the lead. Probing "groupltd" is a wasted lookup;
    # skipping the business entirely is a hole in the search that nothing would report.
    assert significant_words("Group Ltd") == ["group", "ltd"]


# --- slug variants --------------------------------------------------------------


def test_a_long_name_also_proposes_its_leading_words():
    # Both spellings are plausible registrations for the same business.
    assert slug_variants("Casa Verde Bistro") == ["casaverdebistro", "casaverde"]


def test_a_two_word_name_proposes_only_itself():
    # Dropping to one word would be guessing at somebody else's domain, not this business's.
    assert slug_variants("Mercury Drug") == ["mercurydrug"]


def test_slugs_too_short_to_identify_anyone_are_refused():
    assert slug_variants("ABC") == []


def test_a_bare_industry_word_is_never_proposed():
    # `pharmacy.com` belongs to somebody, and it is emphatically not this lead.
    assert slug_variants("Pharmacy") == []
    assert slug_variants("Dental") == []


def test_an_industry_word_survives_as_part_of_a_real_name():
    assert "vigandental" in slug_variants("Vigan Dental")


# --- host generation ------------------------------------------------------------


def test_each_slug_is_tried_across_the_philippine_tlds():
    hosts = candidate_hosts("Mercury Drug")

    assert hosts == [
        "mercurydrug.com", "mercurydrug.ph", "mercurydrug.com.ph", "mercurydrug.net",
    ]


def test_the_number_of_guesses_is_capped():
    # More guesses means more fetches and a weaker margin between candidates, and the marginal guess
    # is the least likely one to be right.
    hosts = candidate_hosts("Casa Verde Bistro and Grill House")

    assert len(hosts) <= MAX_HOSTS_PER_LEAD


def test_a_business_with_no_usable_name_produces_no_hosts():
    assert candidate_hosts("") == []
    assert hosts_for_leads([lead(business="")]) == {"1": []}


# --- candidate assembly ---------------------------------------------------------


def test_only_hosts_that_resolve_become_candidates():
    hosts = {"1": ["mercurydrug.com", "mercurydrug.ph"]}
    result = candidates_for([lead("1", "Mercury Drug")], hosts, {"mercurydrug.com"})

    assert [c["url"] for c in result["1"]] == ["https://mercurydrug.com"]
    assert result["1"][0]["source"] == TIER_PROBE
    assert result["1"][0]["source_detail"] == "dns:mercurydrug.com"


def test_nothing_resolving_is_an_empty_list_not_a_conclusion():
    # "We found no website" is not "there is no website". This tier reports the former and the CRM
    # still refuses to conclude the latter without a human.
    result = candidates_for([lead()], {"1": ["casaverdebistro.com"]}, set())

    assert result["1"] == []


def test_a_url_an_earlier_tier_already_proposed_is_not_proposed_again():
    # The same page fetched twice buys nothing and splits the margin rule against itself.
    result = candidates_for(
        [lead("1", "Mercury Drug")],
        {"1": ["mercurydrug.com"]},
        {"mercurydrug.com"},
        {"1": {"https://mercurydrug.com"}},
    )

    assert result["1"] == []


def test_the_earlier_tier_check_ignores_trailing_slashes_and_case():
    result = candidates_for(
        [lead("1", "Mercury Drug")],
        {"1": ["mercurydrug.com"]},
        {"mercurydrug.com"},
        {"1": {"HTTPS://MercuryDrug.com/"}},
    )

    assert result["1"] == []


def test_every_queued_lead_gets_an_entry_even_when_it_yields_nothing():
    # The runner appends the tier to `tiers_run` for every lead; an absent key here would make a
    # lead look unsearched when it was searched and came up empty.
    result = candidates_for([lead("1"), lead("2", "Pharmacy")], hosts_for_leads([]), set())

    assert set(result) == {"1", "2"}


# --- wildcard TLDs --------------------------------------------------------------


def test_the_longest_matching_tld_wins():
    # `.ph` and `.com.ph` are separate registries with separate wildcard behaviour; matching the
    # shorter one would compare a host against the wrong sentinel.
    assert tld_of("x.com.ph") == ".com.ph"
    assert tld_of("x.ph") == ".ph"
    assert tld_of("x.com") == ".com"
    assert tld_of("x.example") is None


def test_a_wildcard_registry_is_detected_from_a_name_that_cannot_exist():
    resolve = fake_resolver({"WILDCARD.ph": {PH_WILDCARD}, "WILDCARD.com.ph": {PH_WILDCARD}})

    found = wildcard_addresses((".com", ".ph", ".com.ph", ".net"), resolve)

    # Only the registries that answer for anything are recorded.
    assert found == {".ph": {PH_WILDCARD}, ".com.ph": {PH_WILDCARD}}


def test_an_answer_matching_the_registry_sentinel_is_not_evidence():
    wildcards = {".ph": {PH_WILDCARD}}

    assert is_wildcard_answer("casaverdebistro.ph", {PH_WILDCARD}, wildcards) is True


def test_a_host_with_an_address_of_its_own_survives():
    wildcards = {".ph": {PH_WILDCARD}}

    assert is_wildcard_answer("realbusiness.ph", {"1.2.3.4"}, wildcards) is False


def test_one_address_from_a_round_robin_wildcard_is_still_the_wildcard():
    # A wildcard served round-robin returns a different member each lookup; equality would let
    # every one of them through.
    wildcards = {".ph": {PH_WILDCARD, "45.79.222.139"}}

    assert is_wildcard_answer("x.ph", {"45.79.222.139"}, wildcards) is True


def test_a_host_keeping_one_address_outside_the_wildcard_set_survives():
    wildcards = {".ph": {PH_WILDCARD}}

    assert is_wildcard_answer("x.ph", {PH_WILDCARD, "9.9.9.9"}, wildcards) is False


def test_a_tld_with_no_wildcard_is_judged_on_resolution_alone():
    assert is_wildcard_answer("realbusiness.com", {"1.2.3.4"}, {".ph": {PH_WILDCARD}}) is False


def test_a_host_that_does_not_resolve_is_not_a_wildcard_answer():
    # It is excluded for the ordinary reason; conflating the two would misreport why.
    assert is_wildcard_answer("nowhere.ph", set(), {".ph": {PH_WILDCARD}}) is False


# --- the four cases end to end --------------------------------------------------


def test_wildcard_hosts_are_excluded_while_real_ones_survive():
    resolve = fake_resolver({
        "WILDCARD.ph": {PH_WILDCARD},
        "WILDCARD.com.ph": {PH_WILDCARD},
        # A genuinely registered domain, under a wildcarded registry and a clean one.
        "realbusiness.com": {"76.223.54.146"},
        "realbusiness.ph": {"1.2.3.4"},
    })

    live = resolve_hosts(
        [
            "realbusiness.com",        # real, clean TLD          -> kept
            "realbusiness.ph",         # real, wildcarded TLD     -> kept
            "casaverdebistro.com",     # NXDOMAIN, clean TLD      -> dropped
            "casaverdebistro.ph",      # wildcard answer          -> dropped
            "casaverdebistro.com.ph",  # wildcard answer          -> dropped
        ],
        resolver=resolve,
    )

    assert live == {"realbusiness.com", "realbusiness.ph"}


def test_a_lead_whose_every_guess_is_wildcarded_produces_no_candidates():
    # THE POINT OF THE FIX: "nothing was found" is the strongest negative evidence this tier can
    # produce, and before this it was unreachable for any Philippine lead.
    resolve = fake_resolver({"WILDCARD.ph": {PH_WILDCARD}, "WILDCARD.com.ph": {PH_WILDCARD}})
    leads = [lead("1", "Casa Verde Bistro")]
    hosts = hosts_for_leads(leads)

    live = resolve_hosts([h for hs in hosts.values() for h in hs], resolver=resolve)

    assert live == set()
    assert candidates_for(leads, hosts, live)["1"] == []
