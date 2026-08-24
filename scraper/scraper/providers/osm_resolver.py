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

AMBIGUITY DROPS RATHER THAN GUESSES. A wrong resolution is worse than a miss: it attaches a real
``place_id`` to the wrong business, and because the CRM dedups on that key the error becomes
permanent and invisible. So when a proposal matches more than one distinct OSM business, this
records the ambiguity and keeps neither.

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
    ambiguous: list[str] = field(default_factory=list)

    @property
    def resolution_rate(self) -> float:
        """Proven ÷ proposed. The honest measure of how much of a proposer's output was real."""
        if self.proposed_count == 0:
            return 0.0
        return round(len(self.resolved) / self.proposed_count, 4)


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

        for proposal in wanted:
            matches = by_key.get(normalize_name(proposal), {})
            if not matches:
                outcome.unmatched.append(proposal)
            elif len(matches) > 1:
                # Several different real businesses share this name in this area. Which one the
                # proposer meant is unknowable, so take none.
                outcome.ambiguous.append(proposal)
            else:
                outcome.resolved.append(next(iter(matches.values())))

        return outcome
