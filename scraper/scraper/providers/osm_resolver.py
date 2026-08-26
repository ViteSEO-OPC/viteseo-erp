"""Deterministic resolution of PROPOSED business names to real OpenStreetMap businesses.

THE TRUST BOUNDARY OF AI-ASSISTED DISCOVERY. Something upstream — today a language model — proposes
that a business exists. Nothing here believes it. This module asks OSM whether a business by that
name is actually mapped in that area, and only a confirmed match becomes a ``RawBusiness``. A
proposal that does not resolve is DROPPED and never reaches the CRM.

That is what preserves the two properties the pipeline depends on:

* **Identity is never invented.** ``provider_id``, coordinates, phone, email and website all come
  from the OSM element, never from the proposer. A model can influence WHICH businesses we look up;
  it can never influence WHAT WE RECORD about one.
* **Dedup and idempotency survive.** Because the key is a real OSM key, re-running discovery is
  idempotent and an AI-found business dedups correctly against the same business found by the plain
  OSM provider.

MATCHING IS BY CONTAINMENT, NOT EQUALITY, AND THAT IS A DELIBERATE WIDENING. A proposal matches an
OSM element when the proposal's identity-bearing words are all present in the element's. "Mercury
Drug" therefore matches "Mercury Drug Vigan". Requiring exact equality — as this once did — meant
that any branch qualifier a proposer could not guess (a town, a highway, a mall) refused the match,
which rejected precisely the businesses a proposer is most likely to name. Containment is one-way:
the proposal must be contained BY the element, never the reverse, so "Mercury Drug Vigan" proposed
against a bare "Mercury Drug" element still does not match.

THE PROTECTION IS THAT NOTHING IS EVER CHOSEN — not that multiple matches are refused. A proposal
either keeps EVERY distinct business it matched or keeps none of them. That distinction is the whole
safety argument, and it is what makes the following safe:

* **Several BRANCHES — different names, one chain.** "Mercury Drug Vigan" and "Mercury Drug Bantay"
  are two separately mapped businesses with their own keys and coordinates. Both are kept.

* **Several elements sharing ONE name.** Vigan holds two OSM nodes both called simply "Watsons" and
  four called "Mercury Drug", with no branch qualifier at all. These were once refused as ambiguous,
  on the reasoning that picking one would attach a real ``place_id`` to possibly the wrong business.
  That reasoning applied to PICKING. Retaining every one of them picks nothing: each keeps its own
  real OSM key and its own coordinates, so no record is ever attached to the wrong business. They are
  now kept.

  Refusing them was also strictly harsher than the baseline it exists beside: ``discover()`` returns
  both Watsons nodes without hesitation when their tags match. Holding name lookup to a stricter
  standard than the tag sweep cost real businesses — measured, two of them in one Vigan sample — and
  bought nothing, because the risk it guarded against was never present.

WHAT IS STILL REFUSED, and why each is a genuine failure to establish identity:

* **A proposal matching an implausible number of distinct businesses** is matching on a word too
  common to identify anything. Refused wholesale rather than trusted business by business — see
  ``MAX_BRANCH_MATCHES``.

* **An element with no usable OSM key.** ``provider_id`` is the dedup contract; an element that
  cannot supply one has no identity to record, and landing it would create a lead the CRM can never
  match against itself. Such candidates are never resolved, and a proposal whose every match lacked a
  key is recorded as ambiguous — something carried that name, but nothing could be identified.

No network access lives here. The lookup is injected, which keeps the matching rules — the part
worth being sure about — testable without Overpass.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Callable, Iterable

from scraper.models import RawBusiness

#: Words that carry no identity. Without them "ABC Trading Inc." and "XYZ Trading Inc." look alike,
#: which for a dedup key is not a near-miss but a corruption. Mirrors the stopword philosophy of the
#: CRM's WebsiteIdentityVerifier deliberately — the two answer the same question about the same
#: businesses, and they should not disagree about what a name's identity-bearing words are.
STOPWORDS = frozenset({
    "inc", "incorporated", "corp", "corporation", "company", "ltd", "llc", "plc",
    "the", "and", "for", "philippines", "pilipinas", "store", "shop", "branch",
    "enterprises", "enterprise", "trading", "services", "service", "group", "holdings",
})


#: How many DISTINCT BUSINESSES one proposal may resolve to before it is refused wholesale.
#:
#: Containment matching is one-way and start-anchored, so it cannot match arbitrarily — but a very
#: short proposal ("Rose") can still be contained by several unrelated businesses ("Rose Pharmacy",
#: "Rose Bakery"). Past this count the proposal is plainly matching on a word too common to identify
#: anything, and the right response is to trust none of its matches rather than to pick among them.
#:
#: Counted in BUSINESSES rather than in distinct names, because that is the quantity the ceiling is
#: actually about: one name shared by four real branches is one confident answer, while eleven
#: unrelated businesses sharing one word is not.
MAX_BRANCH_MATCHES = 10


def normalize_name(name: str) -> str:
    """A name reduced to its identity-bearing words, lower-cased and order-independent.

    Order independence matters: OSM records "Mercury Drug Vigan" where a proposer might say "Vigan
    Mercury Drug". Those are the same business and must compare equal.
    """
    words = [w for w in re.split(r"[^a-z0-9]+", name.lower()) if w]
    keep = sorted({w for w in words if len(w) >= 2 and w not in STOPWORDS})
    return " ".join(keep)


@dataclass
class ResolutionOutcome:
    """What resolution produced, plus why the rest was refused — the input to run metrics."""

    resolved: list[RawBusiness] = field(default_factory=list)
    proposed_count: int = 0
    unmatched: list[str] = field(default_factory=list)
    #: Proposals that matched OSM elements of which NONE carried a usable key. Something in the area
    #: carries that name, but nothing about it could be identified — distinct from `unmatched`, where
    #: OSM held nothing by that name at all. Note this no longer covers several elements sharing one
    #: name: those are real businesses with real keys and are all retained.
    ambiguous: list[str] = field(default_factory=list)
    #: Proposals refused for resolving to more distinct businesses than {@see MAX_BRANCH_MATCHES}.
    #: Counted apart from `ambiguous` because the two say different things to an operator: one
    #: proposal was unidentifiable, the other was not specific enough to be a proposal at all.
    too_generic: list[str] = field(default_factory=list)

    @property
    def confirmed_count(self) -> int:
        """PROPOSALS that matched at least one real business.

        Distinct from ``len(resolved)``, which counts BUSINESSES — one proposal naming a chain can
        now resolve to several branches, so the two numbers legitimately differ and conflating them
        would let a resolution rate exceed 1.0.
        """
        return (
            self.proposed_count
            - len(self.unmatched)
            - len(self.ambiguous)
            - len(self.too_generic)
        )

    @property
    def resolution_rate(self) -> float:
        """Proven ÷ proposed, measured in PROPOSALS. The honest measure of how much of a proposer's
        output corresponded to something real."""
        if self.proposed_count == 0:
            return 0.0
        return round(self.confirmed_count / self.proposed_count, 4)


class OsmNameResolver:
    """Resolves proposed names against OSM. ``lookup`` performs the batched, area-scoped query."""

    def __init__(self, lookup: Callable[[list[str], str], Iterable[RawBusiness]]) -> None:
        self._lookup = lookup

    def resolve(self, proposals: Iterable[str], *, area: str) -> ResolutionOutcome:
        wanted: list[str] = []
        seen: set[str] = set()
        for proposal in proposals:
            if not isinstance(proposal, str):
                continue
            cleaned = proposal.strip()
            key = normalize_name(cleaned)
            # A proposal that normalizes to nothing ("The Company Inc.") carries no identity to match
            # on; and a proposer repeating itself must not count twice against the resolution rate.
            if cleaned == "" or key == "" or key in seen:
                continue
            seen.add(key)
            wanted.append(cleaned)

        outcome = ResolutionOutcome(proposed_count=len(wanted))
        if not wanted:
            return outcome

        candidates = list(self._lookup(wanted, area))

        # Group OSM results by normalized name, keeping DISTINCT businesses only. Two elements with
        # the same provider_id are one business seen twice, not an ambiguity.
        by_key: dict[str, dict[str, RawBusiness]] = {}
        for candidate in candidates:
            by_key.setdefault(normalize_name(candidate.name), {})[candidate.provider_id] = candidate

        # Each group's identity words, split once here rather than once per proposal.
        group_words = {key: set(key.split()) for key in by_key}

        # Businesses already taken by an earlier proposal. Two proposals can legitimately reach the
        # same branch ("Mercury Drug" and "Mercury Drug Vigan"), and it must appear once.
        taken: set[str] = set()

        for proposal in wanted:
            words = set(normalize_name(proposal).split())
            # CONTAINMENT, ONE-WAY: the proposal's identity words must all appear in the element's.
            matched_keys = [key for key, other in group_words.items() if words <= other]

            if not matched_keys:
                outcome.unmatched.append(proposal)
                continue

            # EVERY distinct business this proposal matched, across every name it matched. Elements
            # with no usable key are excluded here rather than refused later: they have no identity
            # to record, and `provider_id` is the dedup contract.
            businesses = [
                business
                for key in matched_keys
                for provider_id, business in by_key[key].items()
                if provider_id.strip()
            ]

            if not businesses:
                # Something in the area carries this name and nothing about it can be identified.
                outcome.ambiguous.append(proposal)
                continue

            if len(businesses) > MAX_BRANCH_MATCHES:
                # Resolving this widely means the proposal is not identifying a business. Refuse all
                # of it rather than keep an arbitrary subset — which would be choosing.
                outcome.too_generic.append(proposal)
                continue

            # KEEP ALL OR KEEP NONE, NEVER ONE OF SEVERAL. Two nodes both called "Watsons" are two
            # real branches; retaining both attaches each to its own real key and guesses nothing.
            # The refusals above are the only ways a proposal loses, and each of them takes the whole
            # proposal — so no code path here ever selects between candidates.
            for business in businesses:
                if business.provider_id in taken:
                    continue
                taken.add(business.provider_id)
                outcome.resolved.append(business)

        return outcome
