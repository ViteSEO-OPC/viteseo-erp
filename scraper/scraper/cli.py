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
from scraper.website_intelligence.runner import run_audit


def _discover(args: argparse.Namespace) -> int:
    config = Config.load()
    if args.provider:
        config = Config(**{**config.__dict__, "discovery_provider": args.provider})

    provider = get_provider(config)
    print(
        f"Discovering '{args.category}' in '{args.area}' via {provider.name} "
        f"(limit {args.limit})...",
        file=sys.stderr,
    )
    raw = provider.discover(area=args.area, category=args.category, limit=args.limit)
    close = getattr(provider, "close", None)
    if callable(close):
        close()

    businesses = [normalize(r, provider.name) for r in raw]
    print(f"Discovered {len(businesses)} business(es).", file=sys.stderr)

    if args.out:
        with open(args.out, "w", encoding="utf-8") as fh:
            json.dump([b.to_payload() for b in businesses], fh, indent=2, ensure_ascii=False)
        print(f"Wrote {args.out}", file=sys.stderr)

    if args.print_only:
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
        result = client.ingest(businesses, dry_run=not args.commit)
    finally:
        client.close()

    mode = "COMMIT" if args.commit else "DRY RUN"
    summary = result.get("summary", {})
    print(
        f"[{mode}] total={summary.get('total')} valid={summary.get('valid')} "
        f"duplicates={summary.get('duplicates')} updated={summary.get('updated', 0)} "
        f"imported={result.get('importedCount')}"
    )
    return 0


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
