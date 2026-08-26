"""Validation for AI-proposed OSM tag selectors — the trust boundary of tag expansion.

Something upstream — today a language model — proposes that a category can also be found under some
OpenStreetMap tag. Nothing here believes it. Each proposal is checked against the OSM vocabulary this
package already owns, and only what survives is executed as a query.

WHY THIS IS A SMALLER TRUST SURFACE THAN NAMING BUSINESSES. A selector is a QUERY, not a record.
Whatever it matches, Overpass supplies — every business, every ``provider_id``, every coordinate. A
hallucinated tag matches nothing and the sweep comes back empty. There is no shape of wrong answer
that invents a business, because the model's output never describes one.

THE ONE WAY A BAD SELECTOR COULD STILL DO HARM, and the check that stops it: ``amenity=parking`` is a
perfectly well-formed selector, and a sweep for it would land every car park in town as a lead. The
name-lookup path is already protected from that by {@see is_business_element}, but ``discover()`` is
NOT — it trusts its selectors because a human wrote them. Tag expansion is the first path where a
selector is not human-written, so {@see REJECT_NON_BUSINESS} is what keeps that assumption true.

Validation lives here rather than in the CRM deliberately. The consumer that will EXECUTE a selector
is the one that owns the tag vocabulary; a second copy of these rules on the other side of the
network would be a copy that eventually disagrees with the one that matters.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field

from scraper.providers.openstreetmap import (
    _CATEGORY_KEYS,
    _NON_BUSINESS_VALUES,
)

#: OSM keys a proposed selector may use — the keys that describe what a business IS.
#:
#: Reuses the list ``parse_element`` already consults to derive a business's industry, so expansion
#: can never reach a key the parser would not read back. Sharing the constant means the two cannot
#: drift into disagreeing about what counts as a business tag.
ALLOWED_KEYS = frozenset(_CATEGORY_KEYS)

#: OSM value grammar: lower-case, starts with a letter, underscores and digits after. Anything else
#: is not a tag value, whatever it might be.
VALUE_PATTERN = re.compile(r"^[a-z][a-z0-9_]*$")

#: Hard ceiling on accepted selectors per category. Bounds the Overpass query and the blast radius of
#: a bad answer; mirrors the CRM's own ceiling so neither side can be surprised by the other.
MAX_SELECTORS = 8

#: Rejection reasons, kept as codes so run metrics can say WHY without carrying model output.
REJECT_MALFORMED = "malformed"
REJECT_KEY = "key_not_allowed"
REJECT_VALUE = "value_not_a_tag"
REJECT_NON_BUSINESS = "not_a_business"
REJECT_ALREADY_KNOWN = "already_covered"
REJECT_OVER_CAP = "over_cap"


@dataclass
class ExpansionOutcome:
    """Which selectors survived, and why the rest did not — the input to run metrics."""

    #: Accepted selectors as (key, value) pairs, the shape ``_overpass`` consumes.
    selectors: list[tuple[str, str]] = field(default_factory=list)
    proposed_count: int = 0
    #: Rejection reason code -> the raw strings refused for it. Never reaches the CRM.
    rejected: dict[str, list[str]] = field(default_factory=dict)

    @property
    def rejected_count(self) -> int:
        """How many proposals were refused. THE TRUST METRIC for this capability — a model that
        starts proposing invalid tags should become visible before it becomes expensive."""
        return sum(len(v) for v in self.rejected.values())

    def _reject(self, reason: str, raw: str) -> None:
        self.rejected.setdefault(reason, []).append(raw)


def validate_selectors(
    proposed: list[str],
    *,
    known: list[tuple[str, str]] | None = None,
) -> ExpansionOutcome:
    """Refuse everything that is not a usable, business-bearing OSM selector.

    ``known`` is the category's existing selectors; anything matching one of them is dropped as
    already covered, so the expansion sweep never re-queries what the baseline sweep just did.

    Order matters only for reporting: a selector is refused for the FIRST rule it breaks, so the
    reason recorded is the most specific thing wrong with it.
    """
    outcome = ExpansionOutcome()
    already = set(known or ())
    accepted: set[tuple[str, str]] = set()

    for raw in proposed:
        if not isinstance(raw, str):
            continue
        candidate = raw.strip()
        outcome.proposed_count += 1

        if candidate.count("=") != 1:
            outcome._reject(REJECT_MALFORMED, candidate)
            continue

        key, _, value = candidate.partition("=")
        key = key.strip().lower()
        value = value.strip().lower()

        if key not in ALLOWED_KEYS:
            outcome._reject(REJECT_KEY, candidate)
            continue

        if not VALUE_PATTERN.match(value):
            outcome._reject(REJECT_VALUE, candidate)
            continue

        # THE CHECK THAT MATTERS MOST. `discover()` applies no business filter to its results — it
        # trusts its selectors because a human chose them. This is the first path where one is not
        # human-chosen, so `amenity=parking` has to be refused HERE or every car park becomes a lead.
        if value in _NON_BUSINESS_VALUES.get(key, frozenset()):
            outcome._reject(REJECT_NON_BUSINESS, candidate)
            continue

        pair = (key, value)
        if pair in already or pair in accepted:
            outcome._reject(REJECT_ALREADY_KNOWN, candidate)
            continue

        if len(accepted) >= MAX_SELECTORS:
            outcome._reject(REJECT_OVER_CAP, candidate)
            continue

        accepted.add(pair)
        outcome.selectors.append(pair)

    return outcome


def selector_label(selector: tuple[str, str]) -> str:
    """``("amenity", "fast_food")`` -> ``"amenity=fast_food"``. The form used in metrics and logs."""
    return f"{selector[0]}={selector[1]}"
