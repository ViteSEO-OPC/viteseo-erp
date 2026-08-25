"""AI-assisted discovery — the OpenStreetMap baseline PLUS whatever AI expansion can add to it.

    OSM tag sweep (always, first)  ->  AI-proposed names resolved against OSM  ->  merge on provider_id

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

WHAT THIS IS STILL NOT. The AI leg reaches businesses OSM holds under tags the 19 category selectors
do not cover; it does not reach businesses absent from OSM entirely, because
{@see OsmNameResolver} drops anything OSM cannot confirm. Identity always comes from a real OSM
element, so ``provider_id`` stays a real natural key and CRM dedup is untouched.
"""

from __future__ import annotations

import sys
from typing import Any

from scraper.models import RawBusiness
from scraper.providers.base import DiscoveryProvider
from scraper.providers.openstreetmap import OpenStreetMapProvider

#: Per-record provenance. Written onto every record so a lead can say which lane found it — the only
#: way to answer "did the AI leg actually add coverage?" after the fact. The baseline lane keeps the
#: historical `openstreetmap` value so its cohort is continuous with every lead discovered before
#: this class existed.
LANE_BASELINE = "openstreetmap"
LANE_AI = "openai"

#: The AI leg was not attempted because the baseline already satisfied the caller's limit.
SKIPPED_BASELINE_FULL = "baseline_satisfied_limit"

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
                f"  baseline returned {len(merged)}/{limit} — AI expansion has no room to add, "
                f"skipping the model call.",
                file=sys.stderr,
            )
            self._stats = self._compose_stats(
                baseline_count=len(baseline),
                ai_stats={},
                duplicates=0,
                net_new=0,
                skipped=SKIPPED_BASELINE_FULL,
            )
            return list(merged.values())[:limit]

        # THE AI LEG, WRAPPED. Asked for the full limit rather than the shortfall: some of what it
        # resolves will duplicate the baseline, and truncating it to the gap first would throw away
        # the resolutions that were the net-new ones.
        try:
            extra = self._ai.discover(area=area, category=category, limit=limit)
            ai_stats = self._ai_stats()
        except Exception as exc:  # noqa: BLE001 - the baseline must survive anything the AI leg does.
            print(
                f"  AI expansion failed ({type(exc).__name__}) — keeping the {len(baseline)} "
                f"business(es) OpenStreetMap already found.",
                file=sys.stderr,
            )
            self._stats = self._compose_stats(
                baseline_count=len(baseline),
                ai_stats={"execution_status": EXECUTION_ERRORED, "error_reason": type(exc).__name__},
                duplicates=0,
                net_new=0,
            )
            return list(merged.values())[:limit]

        duplicates = 0
        net_new = 0
        for business in extra:
            # Merged on the OSM key alone. A business the tag sweep already found is the SAME
            # business, not a second one — that is exactly the property `provider_id` guarantees.
            if business.provider_id in merged:
                duplicates += 1
                continue
            business.discovered_by = LANE_AI
            merged[business.provider_id] = business
            net_new += 1

        self._stats = self._compose_stats(
            baseline_count=len(baseline),
            ai_stats=ai_stats,
            duplicates=duplicates,
            net_new=net_new,
        )

        if ai_stats.get("execution_status") not in (None, "ok"):
            print(
                f"  AI expansion did not complete ({ai_stats.get('execution_status')}) — keeping "
                f"the {len(baseline)} business(es) OpenStreetMap already found.",
                file=sys.stderr,
            )
        else:
            print(
                f"  baseline {len(baseline)} + AI net-new {net_new} "
                f"({duplicates} already in the baseline).",
                file=sys.stderr,
            )

        # Baseline records were inserted first, so truncation spends the caller's limit on the
        # deterministic lane before the expanded one.
        return list(merged.values())[:limit]

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
            # THE NUMBER THE WHOLE CAPABILITY IS JUDGED ON: businesses the baseline did not already
            # have. Everything else in this block explains it.
            "net_new": net_new,
        }
        if "resolution_rate" in ai_stats:
            stats["resolution_rate"] = ai_stats["resolution_rate"]
        for key in ("model", "prompt_tokens", "completion_tokens"):
            if key in ai_stats:
                stats[key] = ai_stats[key]
        return stats
