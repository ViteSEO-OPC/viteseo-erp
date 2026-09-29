"""VOLUME MODE — AI-assisted nationwide discovery that reaches past the 20-row page.

    one raw-capped OSM sweep per cell (base + AI-expanded tags)  ->  which keys does the CRM hold?
        ->  new businesses, likely-no-website first, up to the task cap  ->  geocode those only

WHY IT EXISTS. The default sweep asks Overpass for the first 20 raw elements of a cell, in id order,
so every run of a cell sees the same 20 and a cell the CRM already knows returns nothing but
duplicates. Measured on a 0.25° Quezon City cell: 174 named dentists in OSM, 17 of them in the CRM,
and the run recorded the cell as exhausted after 17. This reads the whole cell (up to ``raw_cap``) in
the same one query and spends its effort on the businesses the CRM does not have.

OPT-IN AND SEPARATE. Built only when a run passes ``--volume`` to the combined ``openai`` provider.
``AiAssistedDiscoveryProvider``, ``OpenStreetMapProvider.discover()`` and every other provider are
untouched by this module existing.

THE TRUST BOUNDARY IS THE SWEEP'S. Every business comes from a real OSM element matching the base or
an AI-expanded tag; identity, coordinates and contact data are OSM's. The AI name lane does not run.
The AI tag-expansion lane proposes WHERE ELSE TO LOOK and is validated exactly as the composite
validates it; its finds keep the ``openai`` lane provenance they always had.

THE CRM STAYS THE DEDUP. Businesses the CRM already holds are still returned — ungeocoded — so the
ingest sees exactly what a sweep saw and keeps doing what it does with a re-found lead (website
candidates, lane provenance). The known-place lookup only decides which rows are worth a Nominatim
call and a slot under the cap; if it fails, every row is treated as possibly new.

WHAT IT REPORTS FOR THE DESCENT. ``volume_raw_truncated`` (the cap cut the cell off) and
``volume_new_capped`` (more new businesses than this task may return): either means "there is more
here", and the orchestrator subdivides on them instead of on the named-row count.
"""

from __future__ import annotations

import sys
from typing import Any, Callable

from scraper.models import RawBusiness
from scraper.providers.ai_assisted import LANE_AI, LANE_BASELINE
from scraper.providers.base import DiscoveryProvider
from scraper.providers.openstreetmap import OpenStreetMapProvider, category_selectors, parse_element
from scraper.providers.tag_expansion import selector_label, validate_selectors

#: Recorded as the name lane's outcome, so a reader never mistakes "not asked" for "asked and failed".
NAME_LANE_OFF = "volume_mode_name_lane_off"

#: Tags that say a business already has a web presence of its own. Their absence is the only
#: discovery-time hint of a business that will turn out to have no website — which the frozen scorer
#: rewards and the Nearby board ranks High. Used to ORDER new businesses under the cap, never to
#: exclude one.
_WEB_PRESENCE_TAGS = ("website", "contact:website", "url", "brand", "brand:wikidata")


class VolumeDiscoveryProvider(DiscoveryProvider):
    name = "openai"

    def __init__(
        self,
        *,
        osm: OpenStreetMapProvider,
        ai: DiscoveryProvider,
        known: Callable[[list[str]], set[str] | None],
        raw_cap: int,
        task_cap: int,
        call_counter: Any = None,
        closers: tuple[Callable[[], None], ...] = (),
    ) -> None:
        self._osm = osm
        self._ai = ai
        self._known = known
        self._raw_cap = max(1, int(raw_cap))
        self._task_cap = max(1, int(task_cap))
        self._counter = call_counter
        self._closers = closers
        self._stats: dict[str, Any] = {}

    def discover(
        self,
        *,
        area: str,
        category: str,
        limit: int,
        bbox: tuple[float, float, float, float] | None = None,
    ) -> list[RawBusiness]:
        # `limit` is the default sweep's page size and does not bind here: the task cap does.
        before = self._counter.snapshot() if self._counter is not None else {}

        # Unguarded, exactly like the default sweep: an unmapped niche fails the task loudly.
        base = category_selectors(category)
        expansion, extra = self._expansion(category, base)

        box = bbox or self._osm.geocode_area(area)
        sweep = self._osm.sweep_cell(base + extra, box, self._raw_cap)

        rows: dict[str, tuple[RawBusiness, dict]] = {}
        for element in sweep.elements:
            business = parse_element(element)
            if business is None or not business.provider_id.strip() or business.provider_id in rows:
                continue
            tags = element.get("tags") or {}
            # A business matching a base tag is the baseline's, whatever else it matches — the same
            # attribution the composite gives a business both lanes reach.
            business.discovered_by = LANE_BASELINE if _matches(tags, base) else LANE_AI
            rows[business.provider_id] = (business, tags)

        known = self._known(list(rows))
        lookup_ok = known is not None
        known = known or set()

        known_rows = [b for pid, (b, _) in rows.items() if pid in known]
        # Stable sort: within each group the sweep's own order is kept.
        new_rows = sorted(
            ((b, t) for pid, (b, t) in rows.items() if pid not in known),
            key=lambda pair: 0 if _likely_no_website(pair[1]) else 1,
        )
        kept = new_rows[: self._task_cap]

        geocoded = 0
        for business, _ in kept:
            if business.latitude is None or business.longitude is None:
                continue
            if business.region and business.province and business.city:
                continue
            self._osm._fill_admin_area(business)  # noqa: SLF001 - the sweep's own gated reverse geocoder.
            geocoded += 1

        returned = known_rows + [b for b, _ in kept]
        expansion["expansion_discovered"] = sum(1 for b, _ in rows.values() if b.discovered_by == LANE_AI)
        expansion["expansion_net_new"] = sum(1 for b, _ in kept if b.discovered_by == LANE_AI)
        expansion["expansion_by_selector"] = {
            selector_label(sel): {
                "found": sum(1 for _, t in rows.values() if _matches(t, [sel])),
                "net_new": sum(1 for b, t in kept if _matches(t, [sel]) and b.discovered_by == LANE_AI),
            }
            for sel in extra
        }

        self._stats = {
            "execution_status": "ok",
            "error_reason": None,
            "ai_execution_status": NAME_LANE_OFF,
            "ai_error_reason": None,
            "ai_skipped": NAME_LANE_OFF,
            "baseline_discovered": sum(1 for b, _ in rows.values() if b.discovered_by == LANE_BASELINE),
            "ai_duplicates": 0,
            "name_net_new": 0,
            "net_new": expansion["expansion_net_new"],
            **expansion,
            "volume": True,
            "volume_raw_cap": self._raw_cap,
            "volume_raw_elements": len(sweep.elements),
            "volume_raw_truncated": sweep.raw_truncated,
            "volume_named": len(rows),
            "volume_known_lookup": "ok" if lookup_ok else "failed",
            "volume_known": len(known_rows),
            "volume_new_candidates": len(new_rows),
            "volume_task_cap": self._task_cap,
            "volume_new_returned": len(kept),
            "volume_new_capped": len(new_rows) > self._task_cap,
            "volume_likely_no_website": sum(1 for _, t in kept if _likely_no_website(t)),
            "volume_geocoded": geocoded,
        }
        if self._counter is not None:
            now = self._counter.snapshot()
            self._stats["calls"] = {k: now[k] - before.get(k, 0) for k in now}

        print(
            f"  volume: {len(sweep.elements)}/{self._raw_cap} raw, {len(rows)} named, "
            f"{len(known_rows)} known, {len(new_rows)} new -> returning {len(kept)} new "
            f"({geocoded} geocoded)" + ("" if lookup_ok else " [known-places lookup failed]") + ".",
            file=sys.stderr,
        )
        return returned

    def provenance_only(self) -> list[RawBusiness]:
        """Nothing is truncated away from the AI lane here: every expansion find is either returned or
        left for a subdivided child to return. Present so the CLI reports this mode like the composite."""
        return []

    def stats(self) -> dict[str, Any]:
        return dict(self._stats)

    def close(self) -> None:
        # The AI leg owns the OSM client and closes it, exactly as in the composite.
        self._ai.close()
        for close in self._closers:
            close()

    # -- the tag-expansion lane: search STRATEGY, validated before it can become a query ---------

    def _expansion(self, category: str, base: list[tuple[str, str]]) -> tuple[dict[str, Any], list[tuple[str, str]]]:
        stats: dict[str, Any] = {
            "expansion_status": "ok",
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
        expand = getattr(self._ai, "expand_tags", None)
        if not callable(expand):
            stats["expansion_status"] = "unsupported"
            return stats, []
        try:
            proposed, status, error, usage = expand(category, base)
        except Exception as exc:  # noqa: BLE001 - the base sweep must survive anything this lane does.
            stats["expansion_status"] = "provider_errored"
            stats["expansion_error"] = type(exc).__name__
            return stats, []
        if status != "ok":
            stats["expansion_status"] = status
            stats["expansion_error"] = error
            return stats, []
        for key in ("model", "prompt_tokens", "completion_tokens"):
            if key in usage:
                stats[f"expansion_{key}"] = usage[key]

        outcome = validate_selectors(proposed, known=base)
        stats["expansion_proposed"] = outcome.proposed_count
        stats["expansion_rejected"] = outcome.rejected_count
        stats["expansion_rejections"] = {k: len(v) for k, v in outcome.rejected.items()}
        stats["expansion_selectors"] = [selector_label(sel) for sel in outcome.selectors]
        return stats, list(outcome.selectors)


def _matches(tags: dict, selectors: list[tuple[str, str]]) -> bool:
    return any(str(tags.get(key) or "") == value for key, value in selectors)


def _likely_no_website(tags: dict) -> bool:
    return not any(str(tags.get(key) or "").strip() for key in _WEB_PRESENCE_TAGS)
