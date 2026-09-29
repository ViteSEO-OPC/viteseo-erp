"""Per-run discovery telemetry — the evidence behind "which provider is actually better?".

WHY THIS EXISTS. Provider comparison without measurement is anecdote. Every number below except the
model's token usage was already computed during a normal run and then thrown away: the ingest
response already returns total/valid/duplicates/updated/imported, and the provider already knows how
many candidates it produced. This module keeps them.

WHAT IT DELIBERATELY DOES NOT DO. It records the DISCOVERY funnel only, and stops at the CRM
boundary. Downstream outcomes — website discovery, identity verification, qualification, deals — are
already answerable from the CRM by cohorting on ``leads.source``, using definitions the business has
already approved. Re-deriving them here would create a second source of truth for numbers the CRM
already owns, and the two would drift.

A file, not a table, for the same reason ``.discovery-state.json`` is a file: this is runtime
telemetry from a data provider, not CRM business data, and giving it a migration would put operational
exhaust into the schema the CRM's business computations read from.
"""

from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any, Optional

#: Runtime state beside the rotation cursor. NOT version-controlled (see the root .gitignore).
DEFAULT_METRICS_FILE = Path(__file__).resolve().parent.parent / ".discovery-metrics.jsonl"

#: Env override, resolved per call rather than at import. Tests point it at a tmp path so a test run
#: can never append to the real telemetry file — synthetic rows mixed into the evidence would
#: quietly corrupt the provider comparison this file exists to support.
METRICS_FILE_ENV = "DISCOVERY_METRICS_FILE"


def metrics_file(path: Path | str | None = None) -> Path:
    """Where telemetry goes: explicit argument, else the env override, else the default."""
    if path is not None:
        return Path(path)
    override = os.environ.get(METRICS_FILE_ENV)
    return Path(override) if override else DEFAULT_METRICS_FILE


def build_record(
    *,
    provider: str,
    area: str,
    category: str,
    limit: int,
    commit: bool,
    duration_ms: int,
    discovered: int,
    with_website: int,
    ingest_summary: Optional[dict[str, Any]] = None,
    imported: int = 0,
    provider_stats: Optional[dict[str, Any]] = None,
    started_at: Optional[str] = None,
    choice: Any = None,
    provenance_sent: Optional[int] = None,
) -> dict[str, Any]:
    """One run's funnel, flat and provider-agnostic.

    ``proposed`` defaults to ``discovered`` so a provider that does not propose-then-resolve (OSM)
    reports a 1.0 resolution rate rather than a missing field. That keeps the two providers directly
    comparable on one schema instead of forcing every reader to special-case the shape.
    """
    stats = dict(provider_stats or {})
    summary = dict(ingest_summary or {})

    proposed = int(stats.get("proposed", discovered))
    resolved = int(stats.get("resolved", discovered))

    # FIVE SEPARATE FACTS, deliberately not collapsed into one. "OpenAI was selected", "OpenStreetMap
    # actually ran", "the selection came from the CRM rather than a flag", "it fell back because no
    # credential was stored" and "the model rejected our key" are different things, and a reader who
    # can only see the last provider name cannot tell a healthy OSM run from a broken AI one.
    execution_status = str(stats.get("execution_status") or "ok")

    record: dict[str, Any] = {
        "started_at": started_at,
        # What actually ran.
        "provider": provider,
        # What the configuration asked for — equal to `provider` unless a fallback occurred.
        "provider_selected": getattr(choice, "provider", provider),
        # Where the decision came from: cli | crm | env | default.
        "provider_source": getattr(choice, "source", None),
        # Why the effective provider differs from the selected one (CRM's reason code), if it does.
        "fallback_reason": getattr(choice, "reason", None),
        # Whether the provider actually completed. `ok` is the ONLY value that makes the counts below
        # a real result; anything else means the run failed and discovered nothing for that reason.
        "provider_execution_status": execution_status,
        "error_reason": stats.get("error_reason"),
        "succeeded": execution_status == "ok",
        "area": area,
        "category": category,
        "limit": limit,
        "commit": commit,
        "duration_ms": duration_ms,
        # -- discovery funnel --
        "proposed": proposed,
        "resolved": resolved,
        # The provider's own rate wins when it reports one. A proposal naming a chain can resolve to
        # several branches, so businesses ÷ proposals can exceed 1.0 and is not the rate anyone means;
        # the provider divides by PROPOSALS CONFIRMED, which is.
        "resolution_rate": (
            round(float(stats["resolution_rate"]), 4)
            if stats.get("resolution_rate") is not None
            else (round(resolved / proposed, 4) if proposed else 0.0)
        ),
        "unmatched": int(stats.get("unmatched", 0)),
        "ambiguous": int(stats.get("ambiguous", 0)),
        "too_generic": int(stats.get("too_generic", 0)),
        "discovered": discovered,
        # Website DISCOVERY rate — how often a provider supplied a URL at all. Distinct from the
        # CRM's website STATUS, which only the audit may decide; this counts inputs, not verdicts.
        "with_website": with_website,
        "website_discovery_rate": round(with_website / discovered, 4) if discovered else 0.0,
        # -- ingest funnel (straight from the CRM's own response; never recomputed here) --
        "total": summary.get("total"),
        "valid": summary.get("valid"),
        "duplicates": summary.get("duplicates"),
        "updated": summary.get("updated"),
        # Businesses the CRM REFUSED on geography — outside the national boundary it enforces at
        # ingest. Recorded as its own number rather than left to be inferred from
        # `total - valid - duplicates`, because that residual also contains rows that failed ordinary
        # validation, and the two are different findings: one says a provider returned a business in
        # another country, the other says a row was malformed. A feature whose headline promise is
        # "nothing outside the Philippines is stored" has to be able to show the count it rejected.
        # `None` from a CRM that predates the field — absent, never zero, so "no rejections" and "this
        # CRM cannot say" stay distinguishable.
        "rejected": summary.get("rejected"),
        "imported": imported,
    }

    # COMPOSITE LANES, when the provider ran more than one. Absent for a single-lane run for the same
    # reason the cost block is: a plain OSM run has no baseline-versus-expansion split to report, and
    # emitting zeros would claim the AI leg contributed nothing when it was never asked to.
    if "baseline_discovered" in stats:
        record["baseline_discovered"] = int(stats["baseline_discovered"])
        record["ai_duplicates"] = int(stats.get("ai_duplicates", 0))
        # The number the AI capability is judged on: businesses the baseline did not already hold.
        record["net_new"] = int(stats.get("net_new", 0))
        record["confirmed"] = int(stats.get("confirmed", 0))
        # The AI leg's OWN outcome, separate from the run's. A failed expansion beside a healthy
        # baseline is a successful run with a broken integration, and both halves need to be visible.
        record["ai_execution_status"] = stats.get("ai_execution_status")
        record["ai_error_reason"] = stats.get("ai_error_reason")
        record["ai_skipped"] = stats.get("ai_skipped")

        # THE TAG-EXPANSION LANE, reported separately from the name lane because the two are judged
        # separately: one is cheap, cacheable and deterministic, the other is neither.
        # `expansion_rejected` is the trust metric — a model that starts proposing invalid selectors
        # becomes visible here before it becomes expensive. `expansion_by_selector` is what lets a
        # tag that duplicates the baseline entirely be pruned rather than swept forever.
        record["name_net_new"] = int(stats.get("name_net_new", 0))
        record["expansion_status"] = stats.get("expansion_status")
        record["expansion_error"] = stats.get("expansion_error")
        record["expansion_proposed"] = int(stats.get("expansion_proposed", 0))
        record["expansion_selectors"] = stats.get("expansion_selectors") or []
        record["expansion_rejected"] = int(stats.get("expansion_rejected", 0))
        record["expansion_rejections"] = stats.get("expansion_rejections") or {}
        record["expansion_discovered"] = int(stats.get("expansion_discovered", 0))
        record["expansion_duplicates"] = int(stats.get("expansion_duplicates", 0))
        record["expansion_net_new"] = int(stats.get("expansion_net_new", 0))
        record["expansion_by_selector"] = stats.get("expansion_by_selector") or {}

    # PROVENANCE-ONLY AI FINDINGS — the AI-lane businesses the per-task limit dropped, sent beside the
    # batch so an EXISTING lead can still be recorded as found by AI. Absent unless the provider can
    # produce them (the composite), so every other record keeps its exact shape. `recorded` and
    # `outside_ph` come from the CRM's own answer and are None when it did not give one (a dry run,
    # or a CRM that predates the field) — never guessed. None of these feed discovered / duplicates /
    # rejected: those still describe the result, and this is not part of it.
    if provenance_sent is not None:
        answer = summary.get("provenanceOnly") if isinstance(summary.get("provenanceOnly"), dict) else {}
        record["provenance_only_sent"] = provenance_sent
        record["provenance_only_recorded"] = answer.get("recorded")
        record["provenance_only_outside_ph"] = answer.get("outsidePh")

    # AI SEARCH PLANNING, when the provider consulted it. Absent otherwise — an OSM run or a Google
    # run with planning off never asked, and a `false` here would claim a plan was sought and unused.
    # `strategy_applied` is whether a returned plan actually shaped the request; `strategy_status` is
    # how the planning call itself ended (`ok`, `insufficient_credit`, ...). The CRM reads both to
    # decide whether a run may be called AI-planned.
    if "strategy_applied" in stats:
        record["strategy_applied"] = bool(stats["strategy_applied"])
    if stats.get("strategy_status"):
        record["strategy_status"] = str(stats["strategy_status"])

    # A RATE REFUSAL'S SUGGESTED WAIT, when the refusing server gave one. Absent otherwise; the CRM's
    # orchestrator uses it to pace a retry instead of guessing.
    if isinstance(stats.get("retry_after"), int):
        record["retry_after"] = int(stats["retry_after"])

    # Cost, when the provider spends money. Absent for OSM rather than zero — "free" and "not
    # measured" are different claims, and a null says which one this is.
    if "prompt_tokens" in stats or "completion_tokens" in stats:
        record["model"] = stats.get("model")
        record["prompt_tokens"] = stats.get("prompt_tokens")
        record["completion_tokens"] = stats.get("completion_tokens")

    return record


def append(record: dict[str, Any], path: Path | str | None = None) -> None:
    """Append one record. Telemetry must never be able to fail a discovery run that did real work."""
    target = metrics_file(path)
    try:
        with target.open("a", encoding="utf-8") as fh:
            fh.write(json.dumps(record, ensure_ascii=False) + "\n")
    except OSError:
        pass


def load(path: Path | str | None = None) -> list[dict[str, Any]]:
    """Every recorded run. A corrupt line is skipped, not fatal — same self-healing rule as the
    rotation state file, which is also runtime state nobody should have to hand-repair."""
    target = metrics_file(path)
    if not target.exists():
        return []

    records: list[dict[str, Any]] = []
    for line in target.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            parsed = json.loads(line)
        except ValueError:
            continue
        if isinstance(parsed, dict):
            records.append(parsed)
    return records
