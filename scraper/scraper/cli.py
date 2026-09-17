"""Command-line entrypoint for the Lead Enrichment scraper.

    python -m scraper discover --area "Makati City, Philippines" --category cafe --limit 20
    python -m scraper discover --area "Cebu City, Philippines" --category restaurant --commit

Default is a dry run (validates + reports via the CRM ingest, persists nothing). ``--commit`` persists
the discovered businesses as leads. ``--print-only`` skips the CRM entirely and just prints what was
discovered (useful without a CRM token).

``audit`` follows the same rule: it reads the queue and crawls, but submits nothing to the CRM
unless ``--commit`` is given. Every CRM write in this tool is opt-in.
"""

from __future__ import annotations

import argparse
import json
import sys
import time
from datetime import datetime, timezone

from scraper import run_metrics
from scraper.config import Config
from scraper.ingest import CrmIngestClient
from scraper.normalizer import normalize
from scraper.partition import (
    BBox,
    DiscoveryTask,
    clamp_cells,
    format_bbox,
    parse_bbox,
    plan_tasks,
)
from scraper.providers import build_openstreetmap, config_for, get_provider, resolve_provider_choice
from scraper.target_sources import resolve_source
from scraper.targets import (
    DEFAULT_TARGETS_FILE,
    MAX_LIMIT,
    TargetsError,
    advance,
    configured_targets_per_run,
    grouped,
    load_state,
    load_targets,
    save_state,
    select,
    targets_per_run,
)
from scraper.safety import describe_target, refuse_remote_commit
from scraper.website_discovery.runner import run_website_discovery
from scraper.website_intelligence.runner import run_audit


def _planned_sub_areas(config: Config, *, area: str, category: str) -> list[str] | None:
    """Sub-areas the optional AI planner suggests for this scope. ``None`` when it is unavailable.

    Proposals only. Every name still has to survive the geocoder in ``plan_tasks`` before it can
    become a search, which is the same discipline the name lane uses for businesses: the model
    proposes, a deterministic source disposes. Nothing here is trusted as geography.

    OFF UNLESS THE RUN ASKED FOR AI, checked HERE rather than only at the provider. Google Places is
    a factual provider and a Google-only run must reach no model at all — but this planner is invoked
    by the PARTITIONER, which sits upstream of every provider and used to consult it unconditionally.
    A partitioned Google run therefore still opened the strategy channel, and the only thing stopping
    a model call was the CRM refusing at the other end. That is a remote fence, not a local one: it
    depends on a setting in another process staying switched off, and it spends a request to find
    out. ``use_ai_strategy`` is the same flag the provider factory already honours, so "no AI this
    phase" is now one answer given in both places.
    """
    if not config.use_ai_strategy:
        return None

    from scraper.strategy import fetch_strategy

    plan = fetch_strategy(
        crm_base_url=config.crm_base_url,
        crm_token=config.crm_token,
        category=category,
        scope=area,
        timeout=config.openai_timeout,
    )
    return plan.sub_areas if plan is not None else None


def _plan_discovery_tasks(
    config: Config,
    *,
    area: str,
    category: str,
    limit: int,
    partition: int | None,
    bbox: BBox | None = None,
) -> list[DiscoveryTask]:
    """Turn one scope into the bounded tasks this run will execute.

    Unpartitioned is the default and costs NOTHING extra — no geocode, no strategy call, no change
    of any kind. Only an explicit partition opens the geocoder, and it is opened once and shared
    between resolving the scope and validating any proposed sub-area.

    A CALLER-SUPPLIED ``bbox`` REPLACES THE GEOCODER OUTRIGHT. When the box is already known — the
    nationwide planner computes it from the national polygon and clips it before this process is even
    started — resolving ``area`` through Nominatim would be worse than redundant: the geocoder would
    answer with the bounding box of a PLACE NAME, which is a different shape from the cell that was
    planned, and the search would quietly drift off the cell it was supposed to cover. So a supplied
    box is authoritative, ``area`` stays a label, and no geocode is performed.
    """
    cells = clamp_cells(partition)
    if cells <= 1:
        return [DiscoveryTask(area=area, category=category, limit=limit, bbox=bbox)]

    # Already bounded: split the box that was given, and still never geocode or ask for a plan.
    if bbox is not None:
        return plan_tasks(area=area, category=category, limit=limit, cells=cells, bbox=bbox)

    geocoder = build_openstreetmap(config)
    try:
        try:
            bbox = geocoder.geocode_area(area)
        except Exception as exc:  # noqa: BLE001 - an unresolvable scope simply cannot be partitioned.
            print(
                f"  scope could not be resolved for partitioning ({type(exc).__name__}) — "
                f"running it as a single search.",
                file=sys.stderr,
            )
            bbox = None

        return plan_tasks(
            area=area,
            category=category,
            limit=limit,
            cells=cells,
            bbox=bbox,
            sub_areas=_planned_sub_areas(config, area=area, category=category),
            geocode=geocoder.geocode_area,
        )
    finally:
        geocoder.close()


def _run_tasks(provider, tasks: list[DiscoveryTask], limit: int):
    """Execute the planned tasks and merge their results on the provider's own identity key.

    ONE TASK IS THE UNTOUCHED PATH. It calls the provider exactly as this function's caller always
    did — no wrapping, no swallowed exception — so an unpartitioned run behaves identically to before
    partitioning existed, including how it fails.

    ``bbox`` IS PASSED ONLY WHEN THERE IS ONE, which is what keeps that promise literally true: a
    provider written before the partitioning contract existed — a third-party implementation, or a
    test double — is still called with exactly the arguments it has always accepted. An optional
    parameter that is always sent is not optional.

    Several tasks are merged on ``provider_id``, which is the same key the CRM dedups on, so a
    business found in two adjacent cells is one business here rather than a duplicate sent to ingest.
    A failing cell costs only itself: losing an entire scope because one cell timed out would make
    partitioning strictly worse than not partitioning.
    """

    def run(task: DiscoveryTask):
        bounded = {"bbox": task.bbox} if task.bbox is not None else {}
        return provider.discover(
            area=task.area, category=task.category, limit=task.limit, **bounded
        )

    if len(tasks) == 1:
        return run(tasks[0])

    merged = {}
    for task in tasks:
        try:
            found = run(task)
        except Exception as exc:  # noqa: BLE001 - one cell must not cost the others their turn.
            print(f"  task failed ({task.described()}): {type(exc).__name__}", file=sys.stderr)
            continue

        for business in found:
            merged.setdefault(business.provider_id, business)

        if len(merged) >= limit:
            break

    return list(merged.values())[:limit]


def run_discovery(
    config: Config,
    *,
    area: str,
    category: str,
    limit: int,
    commit: bool,
    print_only: bool = False,
    out: str | None = None,
    provider_override: str | None = None,
    partition: int | None = None,
    bbox: BBox | None = None,
    country: str | None = None,
) -> int:
    """Discover one (area, category) pair and ingest it. The single discovery path.

    Extracted verbatim from ``_discover`` so the scheduled ``discover-all`` command reuses the exact
    proven flow — same provider, same normalizer, same ingest client, same ``dry_run = not commit``
    gate — rather than growing a second one that could drift from it.
    """
    # THE COUNTRY FENCE, applied before any provider exists.
    #
    # Carried on the RUN rather than read from this process's environment, because it is a property of
    # what was asked for, not of where the engine happens to be deployed. It reaches the geocoder
    # through the config, and the geocoder is what every provider resolves its search area with — so a
    # location outside the country cannot become a request rather than merely failing to be stored.
    if country:
        config = Config(**{**config.__dict__, "discovery_country": country})

    # THE PROVIDER BOUNDARY. This is the single point where the Settings switch enters the runtime;
    # everything downstream (normalize -> ingest -> prospect -> qualification -> scoring) is identical
    # whichever provider produced the RawBusiness records.
    choice = resolve_provider_choice(config, provider_override)
    provider = get_provider(config_for(config, choice))
    print(
        f"Discovering '{category}' in '{area}' via {provider.name} "
        f"(limit {limit}, provider from {choice.source}"
        + (f", country {config.discovery_country}" if config.discovery_country else "")
        + (f", bounded to {format_bbox(bbox)}" if bbox is not None else "")
        + ")...",
        file=sys.stderr,
    )
    started_at = datetime.now(timezone.utc)
    started = time.monotonic()
    # ONE SCOPE, POSSIBLY SEVERAL BOUNDED SEARCHES. Unpartitioned this is the single call it always
    # was; partitioned it is the only way past a provider's per-request result ceiling.
    tasks = _plan_discovery_tasks(
        config, area=area, category=category, limit=limit, partition=partition, bbox=bbox
    )
    if len(tasks) > 1:
        print(
            f"  partitioned into {len(tasks)} bounded task(s) "
            f"({'sub-areas' if tasks[0].label == 'sub-area' else 'grid cells'}).",
            file=sys.stderr,
        )
    raw = _run_tasks(provider, tasks, limit)

    # Optional per-provider numbers (proposals, resolution rate, token usage). Read the same way
    # `close` is — a provider that has nothing to add simply does not implement it.
    stats_fn = getattr(provider, "stats", None)
    provider_stats = stats_fn() if callable(stats_fn) else {}

    close = getattr(provider, "close", None)
    if callable(close):
        close()

    businesses = [normalize(r, provider.name) for r in raw]
    print(f"Discovered {len(businesses)} business(es).", file=sys.stderr)

    def record_run(ingest_summary: dict | None = None, imported: int = 0) -> None:
        """Log this run's funnel. Called on EVERY path that finished discovery, including the ones
        that stop before the CRM — a provider that proposed 40 names and resolved 0 is exactly the
        result the comparison needs to see, and recording only successful imports would hide it."""
        run_metrics.append(
            run_metrics.build_record(
                provider=provider.name,
                area=area,
                category=category,
                limit=limit,
                commit=commit,
                duration_ms=int((time.monotonic() - started) * 1000),
                discovered=len(businesses),
                with_website=sum(1 for b in businesses if b.website),
                ingest_summary=ingest_summary,
                imported=imported,
                provider_stats=provider_stats,
                started_at=started_at.isoformat(),
                choice=choice,
            )
        )

    if out:
        with open(out, "w", encoding="utf-8") as fh:
            json.dump([b.to_payload() for b in businesses], fh, indent=2, ensure_ascii=False)
        print(f"Wrote {out}", file=sys.stderr)

    # A PROVIDER THAT FAILED TO EXECUTE IS NOT A RUN THAT FOUND NOTHING. Checked before the
    # "nothing discovered" branch below, so a rejected credential or an unreachable model exits
    # non-zero and a scheduler alarms, instead of being logged as a quiet, successful zero.
    execution_status = str(provider_stats.get("execution_status") or "ok")
    if execution_status != "ok":
        record_run()
        error = provider_stats.get("error_reason")
        print(
            f"ERROR: {provider.name} did not complete discovery (status: {execution_status}"
            + (f", {error}" if error else "")
            + "). No candidates were retrieved; this is a FAILED run, not an empty one.",
            file=sys.stderr,
        )
        return 3

    if print_only:
        record_run()
        print(json.dumps([b.to_payload() for b in businesses], indent=2, ensure_ascii=False))
        return 0

    if not businesses:
        record_run()
        print("Nothing discovered — skipping ingest.", file=sys.stderr)
        return 0

    if not config.crm_token:
        print(
            "ERROR: CRM_TOKEN is not set. Set it (or use --print-only to skip the CRM).",
            file=sys.stderr,
        )
        return 2

    client = CrmIngestClient(
        base_url=config.crm_base_url, token=config.crm_token, user_agent=config.user_agent
    )
    try:
        result = client.ingest(businesses, dry_run=not commit)
    finally:
        client.close()

    mode = "COMMIT" if commit else "DRY RUN"
    summary = result.get("summary", {})
    record_run(summary, int(result.get("importedCount") or 0))
    print(
        f"[{mode}] CRM target: {describe_target(config.crm_base_url).label}\n"
        f"[{mode}] total={summary.get('total')} valid={summary.get('valid')} "
        f"duplicates={summary.get('duplicates')} updated={summary.get('updated', 0)} "
        f"imported={result.get('importedCount')}"
    )
    return 0


def run_comparison(config: Config, *, area: str, category: str, limit: int, providers: list[str]) -> dict:
    """Run several providers over ONE target WITHOUT touching the CRM, and compare their candidate sets.

    WHY THIS EXISTS, AND WHY IT MUST BE NON-MUTATING. Comparing providers on IMPORTED leads is
    invalid: the CRM dedups on `place_id`, so whichever provider commits first claims the businesses
    and the second is credited with nothing but duplicates. The result would measure run order, not
    provider quality. Comparing the sets BEFORE either one writes is the only way to get the honest
    answer to "what did this provider find that the other did not".

    Never contacts the CRM, never advances the rotation cursor, never writes a lead. The only
    side effect is a metrics line per provider, which is the point.
    """
    sets: dict[str, dict[str, dict]] = {}
    report_failures: dict[str, str] = {}

    for name in providers:
        run_config = Config(**{**config.__dict__, "discovery_provider": name})
        print(f"\n=== {name}: '{category}' in '{area}' (limit {limit}) ===", file=sys.stderr)

        started_at = datetime.now(timezone.utc)
        started = time.monotonic()
        try:
            # Construction is inside the guard too: an unconfigured provider (no API key) must cost
            # only its own column, exactly as a flaky area costs only its own target in discover-all.
            provider = get_provider(run_config)
            raw = provider.discover(area=area, category=category, limit=limit)
        except Exception as exc:  # noqa: BLE001 - one provider failing must not lose the other's result
            print(f"  {name} failed: {type(exc).__name__}: {exc}", file=sys.stderr)
            report_failures[name] = f"{type(exc).__name__}: {exc}"
            continue

        stats_fn = getattr(provider, "stats", None)
        provider_stats = stats_fn() if callable(stats_fn) else {}
        close = getattr(provider, "close", None)
        if callable(close):
            close()

        businesses = [normalize(r, provider.name) for r in raw]
        # Keyed by place_id — the CRM's own dedup key, so "the same business" means here exactly what
        # it will mean at ingest. Comparing on names instead would flatter whichever provider phrases
        # them differently.
        sets[provider.name] = {b.place_id: b for b in businesses if b.place_id}

        run_metrics.append(
            run_metrics.build_record(
                provider=provider.name, area=area, category=category, limit=limit, commit=False,
                duration_ms=int((time.monotonic() - started) * 1000),
                discovered=len(businesses),
                with_website=sum(1 for b in businesses if b.website),
                provider_stats=provider_stats, started_at=started_at.isoformat(),
            )
        )
        print(f"  {len(businesses)} candidate(s).", file=sys.stderr)

    report: dict = {"area": area, "category": category, "limit": limit, "providers": {}}
    for name, found in sets.items():
        others: set[str] = set()
        for other_name, other_found in sets.items():
            if other_name != name:
                others |= set(other_found)
        unique = set(found) - others
        report["providers"][name] = {
            "candidates": len(found),
            "unique_to_this_provider": len(unique),
            "unique_names": sorted(found[k].business_name for k in unique),
        }

    if report_failures:
        report["failed"] = report_failures

    if len(sets) > 1:
        names = list(sets)
        shared = set(sets[names[0]])
        for other in names[1:]:
            shared &= set(sets[other])
        report["shared_by_all"] = len(shared)

    return report


def _compare(args: argparse.Namespace) -> int:
    config = Config.load()
    providers = [p.strip() for p in args.providers.split(",") if p.strip()]
    report = run_comparison(
        config, area=args.area, category=args.category, limit=args.limit, providers=providers
    )
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0


def _discover(args: argparse.Namespace) -> int:
    # `--provider` is passed through as an OVERRIDE rather than folded into the config, so the
    # precedence (flag > CRM > env > default) is decided in one place and an explicit flag can be
    # told apart from a configured default in the run metrics.
    return run_discovery(
        Config.load(),
        area=args.area,
        category=args.category,
        limit=args.limit,
        commit=args.commit,
        print_only=args.print_only,
        out=args.out,
        provider_override=args.provider,
        partition=args.partition,
        bbox=parse_bbox(args.bbox),
        country=args.country,
    )


def _discover_all(args: argparse.Namespace) -> int:
    """Scheduled discovery: run the next N configured targets in round-robin order.

    The automation boundary in one function. It chooses WHICH (area, category) pairs run and stops
    after a bounded number of them; everything after that choice is the unchanged manual path, so
    automation can never widen what the scraper writes to the CRM.

    Failure is isolated per target: a Nominatim timeout on one pair must not cost the others their
    turn, and must not fail a run that otherwise did useful work. Only an all-targets failure is
    reported as a failed run.
    """
    config = Config.load()

    # Structural validation only — no category whitelist here. Deciding whether a category word is
    # valid belongs to the active provider, and duplicating its vocabulary in the orchestrator would
    # be a second source of truth that drifts the moment a provider is added. An unknown category
    # still fails loudly, per target, via the provider's own error and the isolation below.
    # THE PLUGGABLE SOURCE. A configured registry URL replaces the file without this command learning
    # anything new — validation, grouping and rotation below are unchanged and unaware of the origin.
    source = resolve_source(
        path=args.targets_file,
        url=config.targets_url,
        token=config.crm_token,
        user_agent=config.user_agent,
        timeout=config.request_timeout,
        fallback_path=DEFAULT_TARGETS_FILE,
    )

    try:
        targets = load_targets(source=source)
    except TargetsError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2

    try:
        per_run = targets_per_run(
            configured_targets_per_run(source=source), override=args.targets
        )
    except TargetsError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2

    state = load_state(args.state_file)
    selected = select(targets, state, per_run)

    if not selected:
        print("No targets configured — nothing to discover.", file=sys.stderr)
        return 0

    mode = "COMMIT" if args.commit else "DRY RUN"
    # Group attribution in the run header: with an interleaved rotation, "3 of 27 targets" no longer
    # says whether the run actually spread across the island groups. The counts are what an operator
    # reads the nightly log for, so they belong on the line that is already printed rather than in a
    # second one. A single-group (pre-group) file prints only its cursor, exactly as before.
    buckets = grouped(targets)
    if len(buckets) > 1:
        ran = {name: sum(1 for t in selected if t.group == name) for name in buckets}
        spread = ", ".join(f"{name} {ran[name]}/{len(pairs)}" for name, pairs in buckets.items())
        print(
            f"[{mode}] discover-all: {len(selected)} of {len(targets)} target(s) — {spread}.",
            file=sys.stderr,
        )
    else:
        print(
            f"[{mode}] discover-all: {len(selected)} of {len(targets)} target(s), "
            f"cursor {state.get('cursor', 0)}.",
            file=sys.stderr,
        )

    failures = 0
    for target in selected:
        # `--limit` is clamped to the same ceiling the targets FILE is validated against. Without
        # this the flag was a hole straight through the provider protection: the file could not ask
        # for more than MAX_LIMIT, but `--limit 9999` reached Overpass unchecked. The cap exists for
        # shared community infrastructure, so it cannot be a per-invocation opinion.
        limit = min(args.limit, MAX_LIMIT) if args.limit is not None else target.limit
        try:
            rc = run_discovery(
                config,
                area=target.area,
                category=target.category,
                limit=limit,
                commit=args.commit,
                print_only=args.print_only,
            )
            if rc != 0:
                failures += 1
                print(f"  target failed (exit {rc}): {target}", file=sys.stderr)
        except Exception as exc:  # noqa: BLE001 - one bad target must not abort the run
            failures += 1
            print(f"  target failed: {target} — {type(exc).__name__}: {exc}", file=sys.stderr)

    # A preview must not consume a turn: --print-only leaves the cursor where it was, so the next
    # real run covers exactly the targets the preview showed.
    if not args.print_only:
        save_state(advance(state, targets, selected), args.state_file)

    succeeded = len(selected) - failures
    print(f"[{mode}] discover-all complete: {succeeded} succeeded, {failures} failed.")

    # Partial success is still success — the scheduler should not alarm because one area was flaky.
    return 1 if failures == len(selected) else 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="scraper", description="Lead Enrichment discovery scraper.")
    sub = parser.add_subparsers(dest="command", required=True)

    d = sub.add_parser("discover", help="Discover businesses and ingest them into the CRM.")
    d.add_argument("--area", required=True, help='Place name, e.g. "Makati City, Philippines".')
    d.add_argument("--category", required=True, help='Category word (e.g. "cafe") or "key=value".')
    d.add_argument("--limit", type=int, default=20, help="Max businesses to discover (default 20).")
    d.add_argument("--commit", action="store_true", help="Persist leads (default is a dry run).")
    d.add_argument("--print-only", action="store_true", help="Print discovered data; skip the CRM.")
    d.add_argument("--provider", help="Override the discovery provider (default from config).")
    d.add_argument(
        "--country",
        default=None,
        help=(
            "Restrict discovery to this ISO 3166-1 alpha-2 country (e.g. PH). The geocoder will "
            "resolve --area only within it, so a location outside the country cannot be searched "
            "at all — the run reports an unresolved area and makes NO provider request. Without "
            "this flag the geocoder is unrestricted, which is the behaviour that predates it."
        ),
    )
    d.add_argument(
        "--bbox",
        default=None,
        help=(
            "Restrict the search to south,west,north,east (decimal degrees, latitude first). "
            "When given, --area is only a LABEL: the box is authoritative and no geocoding is "
            "performed. This is how a pre-computed cell — e.g. one clipped to a national "
            "boundary — is searched exactly as planned."
        ),
    )
    d.add_argument("--out", help="Also write the normalized payload to this JSON file.")
    d.add_argument(
        "--partition",
        type=int,
        default=None,
        help=(
            "Split the area into up to N bounded searches (default: 1, no partitioning). Use it when "
            "an area is too large to answer in one provider request. The limit is DIVIDED across the "
            "tasks, never multiplied."
        ),
    )
    d.set_defaults(func=_discover)

    da = sub.add_parser(
        "discover-all",
        help="Scheduled discovery: run the next configured target(s) in round-robin order.",
    )
    da.add_argument(
        "--targets",
        type=int,
        default=None,
        help="How many configured targets to run. Overrides defaults.targets_per_run in the "
        "targets file; that in turn overrides the built-in default of 3.",
    )
    da.add_argument(
        "--limit",
        type=int,
        default=None,
        help="Override the per-target limit from the targets file.",
    )
    da.add_argument("--commit", action="store_true", help="Persist leads (default is a dry run).")
    da.add_argument(
        "--print-only",
        action="store_true",
        help="Print discovered data, skip the CRM, and do NOT advance the rotation cursor.",
    )
    da.add_argument("--targets-file", default=None, help="Override the targets file path.")
    da.add_argument("--state-file", default=None, help="Override the rotation state file path.")
    da.set_defaults(func=_discover_all)

    c = sub.add_parser(
        "compare",
        help="Run providers over the SAME target without touching the CRM and diff their candidates.",
    )
    c.add_argument("--area", required=True, help='Place name, e.g. "Makati City, Philippines".')
    c.add_argument("--category", required=True, help='Category word (e.g. "cafe") or "key=value".')
    c.add_argument("--limit", type=int, default=20, help="Max businesses per provider (default 20).")
    c.add_argument(
        "--providers",
        default="osm,openai",
        help="Comma-separated providers to compare (default: osm,openai).",
    )
    c.set_defaults(func=_compare)

    fw = sub.add_parser(
        "find-websites",
        help="Find candidate websites for leads that have none (Tier 0: OSM tags + Wikidata).",
    )
    fw.add_argument("--limit", type=int, default=50, help="Max leads to search (default 50).")
    fw.add_argument(
        "--commit",
        action="store_true",
        help="Record the evidence in the CRM (default is a dry run that submits nothing).",
    )
    fw.set_defaults(func=_find_websites)

    a = sub.add_parser("audit", help="Audit the websites of leads that have a URL (Website Intelligence).")
    a.add_argument("--limit", type=int, default=50, help="Max websites to audit (default 50).")
    a.add_argument(
        "--commit",
        action="store_true",
        help="Submit audit findings to the CRM (default is a dry run).",
    )
    a.set_defaults(func=_audit)

    args = parser.parse_args(argv)

    # ONE choke point for every write, present and future. Enforcing this per-command would mean each
    # new `--commit` flag has to remember the rule; enforcing it on the parsed args means a command
    # that forgets is still covered, because the flag itself is what triggers the check.
    if getattr(args, "commit", False):
        refusal = refuse_remote_commit(describe_target(Config.load().crm_base_url), commit=True)
        if refusal:
            print(f"ERROR: {refusal}", file=sys.stderr)
            return 2

    try:
        return args.func(args)
    except ValueError as exc:
        # Provider selection/configuration errors (unknown provider, missing API key). Same
        # presentation as the targets-file errors above — a clear line, not a stack trace.
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


def _audit(args: argparse.Namespace) -> int:
    return run_audit(Config.load(), args.limit, commit=args.commit)


def _find_websites(args: argparse.Namespace) -> int:
    """Tier 0 candidate discovery. Proposes and verifies; never concludes absence."""
    return run_website_discovery(Config.load(), args.limit, commit=args.commit)
