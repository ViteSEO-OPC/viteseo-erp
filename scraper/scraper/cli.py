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
from scraper.providers import config_for, get_provider, resolve_provider_choice
from scraper.targets import (
    MAX_LIMIT,
    TargetsError,
    advance,
    configured_targets_per_run,
    load_state,
    load_targets,
    save_state,
    select,
    targets_per_run,
)
from scraper.website_discovery.runner import run_website_discovery
from scraper.website_intelligence.runner import run_audit


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
) -> int:
    """Discover one (area, category) pair and ingest it. The single discovery path.

    Extracted verbatim from ``_discover`` so the scheduled ``discover-all`` command reuses the exact
    proven flow — same provider, same normalizer, same ingest client, same ``dry_run = not commit``
    gate — rather than growing a second one that could drift from it.
    """
    # THE PROVIDER BOUNDARY. This is the single point where the Settings switch enters the runtime;
    # everything downstream (normalize -> ingest -> prospect -> qualification -> scoring) is identical
    # whichever provider produced the RawBusiness records.
    choice = resolve_provider_choice(config, provider_override)
    provider = get_provider(config_for(config, choice))
    print(
        f"Discovering '{category}' in '{area}' via {provider.name} "
        f"(limit {limit}, provider from {choice.source})...",
        file=sys.stderr,
    )
    started_at = datetime.now(timezone.utc)
    started = time.monotonic()
    raw = provider.discover(area=area, category=category, limit=limit)

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
    try:
        targets = load_targets(args.targets_file)
    except TargetsError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2

    try:
        per_run = targets_per_run(
            configured_targets_per_run(args.targets_file), override=args.targets
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
    d.add_argument("--out", help="Also write the normalized payload to this JSON file.")
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
