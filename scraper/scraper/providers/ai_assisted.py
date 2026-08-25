"""AI-assisted discovery — the OpenStreetMap baseline PLUS whatever AI expansion can add to it.

    OSM tag sweep (always, first)  ->  AI-expanded tag sweep  ->  AI-proposed names  ->  merge on key

THREE LANES, ONE OF WHICH IS NOT OPTIONAL. The tag sweep is the accepted default and always runs. The
other two are AI-assisted and may each fail independently; neither can subtract from the first.

WHAT THIS FIXES. Selecting the AI provider used to REPLACE the OpenStreetMap sweep: ``discover()``
never ran, so the AI option could legitimately return fewer businesses than doing nothing. That made
the safest choice the one nobody would pick. Here the baseline runs first and unconditionally, and
the AI leg can only ever ADD to it — the result is a strict superset of the default.

THE SAFETY PROPERTY IS STRUCTURAL, NOT DEFENSIVE. Because OSM has already returned before any AI
call is made, there is no failure mode in which AI trouble reduces the default result. A timeout, a
rejected credential, a rate limit, an unreadable body, an Overpass error inside the resolver — every
one of them lands on "the baseline stands". That is why the AI leg is wrapped rather than trusted:
the guarantee should not depend on the AI provider being well-behaved.

AND A FAILED AI LEG IS NOT A FAILED RUN. ``run_discovery`` exits non-zero on a non-``ok``
``execution_status``, which is right when the provider IS the run. Here it is not: the run genuinely
succeeded and delivered the baseline. So the composite reports its own ``ok`` and carries the AI
leg's real outcome alongside it in ``ai_execution_status``, which keeps the failure visible without
throwing away a good run. Silence would be the wrong fix; so would an alarm.

THE AI LEG IS SKIPPED WHEN THERE IS NOTHING TO ADD. If the baseline already returned the caller's
full ``limit``, expansion has no room to contribute and the model is not called at all. Free coverage
is preferred to paid coverage, and an untaken call is the cheapest one available.

THE TWO AI LANES DO DIFFERENT WORK, and the second is the cheaper one. Name proposal asks the model
WHICH BUSINESSES might exist and confirms each against OSM. Tag expansion asks WHERE ELSE TO LOOK —
which OSM tags the category hides under — and Overpass then supplies the businesses outright. Measured
on live Vigan data, the restaurant category gains 68% from three expanded tags, against 8 businesses
from the whole name lane: a query is a better thing to ask a model for than a fact.

WHAT THIS IS STILL NOT. Both AI lanes reach businesses OSM holds; neither reaches businesses absent
from OSM entirely. Identity always comes from a real OSM element, so ``provider_id`` stays a real
natural key and CRM dedup is untouched.
"""

from __future__ import annotations

import sys
from typing import Any

from scraper.models import RawBusiness
from scraper.providers.base import DiscoveryProvider
from scraper.providers.openstreetmap import OpenStreetMapProvider, category_selectors
from scraper.providers.tag_expansion import selector_label, validate_selectors

#: Per-record provenance. Written onto every record so a lead can say which lane found it — the only
#: way to answer "did the AI leg actually add coverage?" after the fact. The baseline lane keeps the
#: historical `openstreetmap` value so its cohort is continuous with every lead discovered before
#: this class existed.
LANE_BASELINE = "openstreetmap"
LANE_AI = "openai"

#: The AI lanes were not attempted because the baseline already satisfied the caller's limit.
SKIPPED_BASELINE_FULL = "baseline_satisfied_limit"

#: The AI leg predates tag expansion and does not offer the capability. Not a failure.
EXPANSION_UNSUPPORTED = "unsupported"

#: The AI leg raised instead of returning a status. Its own paths are careful, but the resolver
#: reaches Overpass and Nominatim, which fail in ways it does not model.
EXECUTION_ERRORED = "provider_errored"


class AiAssistedDiscoveryProvider(DiscoveryProvider):
    """OpenStreetMap first, AI expansion second, merged on the OSM key.

    ``name`` stays ``openai`` because it is the id the CRM setting, the provider registry and
    ``ProviderChoice`` all already use; changing it would rewrite what "the AI provider is selected"
    means everywhere. What each individual lead was found BY is carried per record instead.
    """

    name = "openai"

    def __init__(self, *, osm: OpenStreetMapProvider, ai: DiscoveryProvider) -> None:
        self._osm = osm
        self._ai = ai
        self._stats: dict[str, Any] = {}

    def discover(self, *, area: str, category: str, limit: int) -> list[RawBusiness]:
        # THE BASELINE, UNCHANGED AND UNCONDITIONAL. The exact call a plain OpenStreetMap run makes,
        # against the same untouched `discover()`. Everything after this point can only add.
        baseline = self._osm.discover(area=area, category=category, limit=limit)
        for business in baseline:
            business.discovered_by = LANE_BASELINE

        merged: dict[str, RawBusiness] = {b.provider_id: b for b in baseline}

        if len(merged) >= limit:
            print(
                f"  baseline returned {len(merged)}/{limit} — the AI lanes have no room to add, "
                f"skipping both model calls.",
                file=sys.stderr,
            )
            self._stats = self._compose_stats(
                baseline_count=len(baseline),
                expansion=self._skipped_expansion(SKIPPED_BASELINE_FULL),
                ai_stats={},
                duplicates=0,
                net_new=0,
                skipped=SKIPPED_BASELINE_FULL,
            )
            return list(merged.values())[:limit]

        # LANE 2 — TAG EXPANSION. Merges into `merged` in place and reports what it contributed.
        # Runs before the name lane so a business reachable by BOTH is attributed to the cheaper,
        # fully deterministic route.
        expansion = self._expand_lane(area=area, category=category, limit=limit, merged=merged)

        # LANE 3 — NAME PROPOSALS, WRAPPED. Asked for the full limit rather than the shortfall: some
        # of what it resolves will duplicate what is already held, and truncating it to the gap first
        # would throw away the resolutions that were the net-new ones.
        try:
            extra = self._ai.discover(area=area, category=category, limit=limit)
            ai_stats = self._ai_stats()
        except Exception as exc:  # noqa: BLE001 - the baseline must survive anything the AI lanes do.
            print(
                f"  AI name proposal failed ({type(exc).__name__}) — keeping the "
                f"{len(merged)} business(es) already found.",
                file=sys.stderr,
            )
            self._stats = self._compose_stats(
                baseline_count=len(baseline),
                expansion=expansion,
                ai_stats={"execution_status": EXECUTION_ERRORED, "error_reason": type(exc).__name__},
                duplicates=0,
                net_new=0,
            )
            return list(merged.values())[:limit]

        duplicates = 0
        net_new = 0
        for business in extra:
            # Merged on the OSM key alone. A business another lane already found is the SAME
            # business, not a second one — that is exactly the property `provider_id` guarantees.
            if business.provider_id in merged:
                duplicates += 1
                continue
            business.discovered_by = LANE_AI
            merged[business.provider_id] = business
            net_new += 1

        self._stats = self._compose_stats(
            baseline_count=len(baseline),
            expansion=expansion,
            ai_stats=ai_stats,
            duplicates=duplicates,
            net_new=net_new,
        )

        if ai_stats.get("execution_status") not in (None, "ok"):
            print(
                f"  AI name proposal did not complete ({ai_stats.get('execution_status')}) — keeping "
                f"the {len(merged)} business(es) already found.",
                file=sys.stderr,
            )
        else:
            print(
                f"  baseline {len(baseline)} + tags {expansion['expansion_net_new']} "
                f"+ names {net_new} net-new.",
                file=sys.stderr,
            )

        # Baseline records were inserted first, so truncation spends the caller's limit on the
        # deterministic lane before the expanded ones.
        return list(merged.values())[:limit]

    # -- lane 2: AI-expanded tag sweep ---------------------------------------------------------

    def _expand_lane(
        self, *, area: str, category: str, limit: int, merged: dict[str, RawBusiness]
    ) -> dict[str, Any]:
        """Ask which OSM tags this category also hides under, then sweep them deterministically.

        MERGES IN PLACE and returns only its own numbers. Every failure path returns those numbers
        with a status and leaves `merged` exactly as it was — the baseline cannot be reduced by
        anything that happens here, including an exception, because nothing is ever removed.

        The model's answer is a QUERY, never a record. Overpass supplies every business, every
        provider_id and every coordinate, so the worst a wrong selector can do is describe a sweep
        that matches nothing.
        """
        stats = self._skipped_expansion(None)
        # Snapshot BEFORE this lane merges, so per-selector net-new is measured against the baseline
        # rather than against whichever selector happened to be processed first.
        before = set(merged)

        expand = getattr(self._ai, "expand_tags", None)
        if not callable(expand):
            # An AI leg without the capability is not a failure — it is an older provider.
            stats["expansion_status"] = EXPANSION_UNSUPPORTED
            return stats

        try:
            known = category_selectors(category)
        except ValueError:
            # A raw selector or an unknown category word. Nothing to tell the model it already has.
            known = []

        try:
            proposed, status, error, usage = expand(category, known)
        except Exception as exc:  # noqa: BLE001 - see the docstring.
            stats["expansion_status"] = EXECUTION_ERRORED
            stats["expansion_error"] = type(exc).__name__
            return stats

        if status != "ok":
            stats["expansion_status"] = status
            stats["expansion_error"] = error
            print(
                f"  tag expansion did not complete ({status}) — the baseline sweep is unaffected.",
                file=sys.stderr,
            )
            return stats

        for key in ("model", "prompt_tokens", "completion_tokens"):
            if key in usage:
                stats[f"expansion_{key}"] = usage[key]

        outcome = validate_selectors(proposed, known=known)
        stats["expansion_proposed"] = outcome.proposed_count
        stats["expansion_rejected"] = outcome.rejected_count
        stats["expansion_rejections"] = {k: len(v) for k, v in outcome.rejected.items()}
        stats["expansion_selectors"] = [selector_label(sel) for sel in outcome.selectors]

        if not outcome.selectors:
            return stats

        try:
            sweep = self._osm.find_by_selectors(outcome.selectors, area=area, limit=limit)
        except Exception as exc:  # noqa: BLE001 - an Overpass failure here must not lose the baseline.
            stats["expansion_status"] = EXECUTION_ERRORED
            stats["expansion_error"] = type(exc).__name__
            return stats

        stats["expansion_discovered"] = len(sweep.businesses)
        for business in sweep.businesses:
            if business.provider_id in merged:
                stats["expansion_duplicates"] += 1
                continue
            business.discovered_by = LANE_AI
            merged[business.provider_id] = business
            stats["expansion_net_new"] += 1

        # PER-SELECTOR ATTRIBUTION, measured against the pre-lane snapshot. A selector that returns
        # businesses the baseline already held earns nothing and should be pruned — measured on live
        # data, `healthcare=pharmacy` returns twelve businesses and zero net-new. These counts may
        # sum to more than `expansion_net_new`, because one business can carry two matched tags;
        # the lane total is deduplicated and each selector's figure answers "what would we lose by
        # dropping this one?".
        stats["expansion_by_selector"] = {
            label: {
                "found": len(ids),
                "net_new": sum(1 for pid in ids if pid not in before),
            }
            for label, ids in sweep.by_selector.items()
        }
        return stats

    @staticmethod
    def _skipped_expansion(skipped: str | None) -> dict[str, Any]:
        """A zeroed expansion block. `expansion_status` of `ok` with no selectors means the lane ran
        and had nothing to add, which is a different fact from it not having run."""
        return {
            "expansion_status": skipped or "ok",
            "expansion_error": None,
            "expansion_proposed": 0,
            "expansion_selectors": [],
            "expansion_rejected": 0,
            "expansion_rejections": {},
            "expansion_discovered": 0,
            "expansion_duplicates": 0,
            "expansion_net_new": 0,
            "expansion_by_selector": {},
        }

    def stats(self) -> dict[str, Any]:
        """Per-run numbers for the metrics record. Read optionally by the CLI, like ``close()``."""
        return dict(self._stats)

    def close(self) -> None:
        # The AI leg owns the shared OSM client and closes it; closing here as well would be the
        # kind of duplicate teardown that only shows up under a provider that does not tolerate it.
        self._ai.close()

    # -- internals ---------------------------------------------------------------------------

    def _ai_stats(self) -> dict[str, Any]:
        stats_fn = getattr(self._ai, "stats", None)
        return dict(stats_fn()) if callable(stats_fn) else {}

    def _compose_stats(
        self,
        *,
        baseline_count: int,
        expansion: dict[str, Any],
        ai_stats: dict[str, Any],
        duplicates: int,
        net_new: int,
        skipped: str | None = None,
    ) -> dict[str, Any]:
        """The composite's funnel: what each lane contributed, and what the AI leg actually did.

        ``execution_status`` is the COMPOSITE's, and it is ``ok`` whenever the baseline ran — the run
        did real work and must not be reported as failed. The AI leg's own verdict travels beside it
        so a broken integration stays diagnosable rather than being absorbed by a healthy baseline.
        """
        stats: dict[str, Any] = {
            # The composite completed. See the docstring for why this is not the AI leg's status.
            "execution_status": "ok",
            "error_reason": None,
            # -- what the AI leg actually did --
            "ai_execution_status": skipped or str(ai_stats.get("execution_status") or "ok"),
            "ai_error_reason": ai_stats.get("error_reason"),
            "ai_skipped": skipped,
            # -- the two lanes --
            "baseline_discovered": baseline_count,
            "proposed": int(ai_stats.get("proposed", 0)),
            "resolved": int(ai_stats.get("resolved", 0)),
            "confirmed": int(ai_stats.get("confirmed", 0)),
            "unmatched": int(ai_stats.get("unmatched", 0)),
            "ambiguous": int(ai_stats.get("ambiguous", 0)),
            "too_generic": int(ai_stats.get("too_generic", 0)),
            "ai_duplicates": duplicates,
            # Split by lane, because they are judged separately: tag expansion is cheap, cacheable
            # and deterministic, name proposal is neither. A capability that only pays for itself in
            # aggregate is one nobody can decide about.
            "name_net_new": net_new,
            # THE NUMBER THE WHOLE CAPABILITY IS JUDGED ON: businesses no earlier lane already had.
            # Everything else in this block explains it.
            "net_new": net_new + int(expansion.get("expansion_net_new", 0)),
            **expansion,
        }
        if "resolution_rate" in ai_stats:
            stats["resolution_rate"] = ai_stats["resolution_rate"]
        for key in ("model", "prompt_tokens", "completion_tokens"):
            if key in ai_stats:
                stats[key] = ai_stats[key]
        return stats
