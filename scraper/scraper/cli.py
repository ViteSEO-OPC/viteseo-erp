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

from scraper.config import Config
from scraper.ingest import CrmIngestClient
from scraper.normalizer import normalize
from scraper.providers import get_provider
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
) -> int:
    """Discover one (area, category) pair and ingest it. The single discovery path.

    Extracted verbatim from ``_discover`` so the scheduled ``discover-all`` command reuses the exact
    proven flow — same provider, same normalizer, same ingest client, same ``dry_run = not commit``
    gate — rather than growing a second one that could drift from it.
    """
    provider = get_provider(config)
    print(
        f"Discovering '{category}' in '{area}' via {provider.name} (limit {limit})...",
        file=sys.stderr,
    )
    raw = provider.discover(area=area, category=category, limit=limit)
    close = getattr(provider, "close", None)
    if callable(close):
        close()

    businesses = [normalize(r, provider.name) for r in raw]
    print(f"Discovered {len(businesses)} business(es).", file=sys.stderr)

    if out:
        with open(out, "w", encoding="utf-8") as fh:
            json.dump([b.to_payload() for b in businesses], fh, indent=2, ensure_ascii=False)
        print(f"Wrote {out}", file=sys.stderr)

    if print_only:
        print(json.dumps([b.to_payload() for b in businesses], indent=2, ensure_ascii=False))
        return 0

    if not businesses:
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
    print(
        f"[{mode}] total={summary.get('total')} valid={summary.get('valid')} "
        f"duplicates={summary.get('duplicates')} updated={summary.get('updated', 0)} "
        f"imported={result.get('importedCount')}"
    )
    return 0


def _discover(args: argparse.Namespace) -> int:
    config = Config.load()
    if args.provider:
        config = Config(**{**config.__dict__, "discovery_provider": args.provider})

    return run_discovery(
        config,
        area=args.area,
        category=args.category,
        limit=args.limit,
        commit=args.commit,
        print_only=args.print_only,
        out=args.out,
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

    a = sub.add_parser("audit", help="Audit the websites of leads that have a URL (Website Intelligence).")
    a.add_argument("--limit", type=int, default=50, help="Max websites to audit (default 50).")
    a.add_argument(
        "--commit",
        action="store_true",
        help="Submit audit findings to the CRM (default is a dry run).",
    )
    a.set_defaults(func=_audit)

    args = parser.parse_args(argv)
    return args.func(args)


def _audit(args: argparse.Namespace) -> int:
    return run_audit(Config.load(), args.limit, commit=args.commit)
