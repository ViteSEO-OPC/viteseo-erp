"""Discovery target list + round-robin rotation for the scheduled ``discover-all`` command.

This module is deliberately **inert**: it parses a file, validates it, and decides which
``(area, category)`` pairs come next. It performs no discovery, no normalization, and no HTTP — the
proven ``discover`` path owns all of that, unchanged. Everything here is pure except two small file
reads/writes, which keeps the scheduled command testable without a network or a CRM.

**Why a file and not a CRM table.** Two registries look like the obvious home and both are traps:
``crm_service_areas`` is the Target Service Area registry that feeds the frozen Target Location (+10)
Lead Score signal — adding a discovery area there would silently move Lead Scores; and ``crm_niches``
holds CRM *industry* labels (Consulting, Healthcare, …), not provider selectors (``pharmacy``,
``dentist``, …). The category vocabulary here belongs to the discovery PROVIDER, so binding it to a
CRM table would leak OSM's tag vocabulary into the CRM and break the provider-agnostic boundary.

If admin-managed targets are ever required, the seam is {@see load_targets}: point it at an API
response instead of a file. Nothing else in this module would change.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable

#: Where the version-controlled target list lives (``scraper/discovery-targets.json``).
DEFAULT_TARGETS_FILE = Path(__file__).resolve().parent.parent / "discovery-targets.json"

#: Round-robin cursor + per-pair history. Runtime state, NOT version-controlled (see .gitignore).
DEFAULT_STATE_FILE = Path(__file__).resolve().parent.parent / ".discovery-state.json"

#: Hard ceiling on a single pair's discovery, whatever the file asks for. A typo of `1000` in the
#: targets file must not become a thousand-lead run. This is a PROVIDER protection, not a product
#: preference — Overpass and Nominatim are shared community infrastructure and the scraper is a
#: guest on them — so it stays regardless of how the operational range is tuned.
MAX_LIMIT = 50

#: Default businesses per (area, category) when the targets file does not say. The operational range
#: is 10–30; 30 is preferred because the pool should build CONTINUOUSLY rather than trickle.
DEFAULT_LIMIT = 30

#: Default targets per scheduled run. >1 so one execution advances several pairs and the rotation
#: completes in days rather than weeks. Overridable per run with `--targets`.
DEFAULT_TARGETS_PER_RUN = 3

#: Ceiling on targets per run, mirroring MAX_LIMIT: a scheduling mistake must not turn one nightly
#: execution into a full sweep of every configured pair at full limit.
MAX_TARGETS_PER_RUN = 10


class TargetsError(ValueError):
    """The targets file is missing, malformed, or names something the provider cannot discover."""


@dataclass(frozen=True)
class Target:
    """One unit of scheduled work: discover ``category`` businesses in ``area``, at most ``limit``."""

    area: str
    category: str
    limit: int

    @property
    def key(self) -> str:
        """Stable identity for the state file.

        Keyed by VALUE, not by list position, so reordering or inserting entries in the targets file
        never mis-attributes a pair's history to a different pair.
        """
        return f"{self.area}|{self.category}"

    def __str__(self) -> str:  # pragma: no cover - display only
        return f"{self.category} in {self.area} (limit {self.limit})"


def load_targets(
    path: Path | str | None = None,
    *,
    known_categories: Iterable[str] | None = None,
) -> list[Target]:
    """Parse the targets file into a flat, ordered list of ``(area, category)`` pairs.

    Validation is strict and fails the whole run rather than silently skipping entries: a scheduled
    job nobody is watching must not quietly discover less than it was configured to.

    :param known_categories: when given, every category is checked against it up front, so a typo
        surfaces at parse time instead of midway through a run that has already committed leads.
    """
    file = Path(path) if path is not None else DEFAULT_TARGETS_FILE

    if not file.exists():
        raise TargetsError(f"Targets file not found: {file}")

    try:
        raw: Any = json.loads(file.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise TargetsError(f"Targets file is not valid JSON ({file}): {exc}") from exc

    if not isinstance(raw, dict):
        raise TargetsError(f"Targets file must be a JSON object, got {type(raw).__name__}.")

    defaults = raw.get("defaults", {})
    if not isinstance(defaults, dict):
        raise TargetsError("Targets file 'defaults' must be an object.")

    default_limit = _coerce_limit(defaults.get("limit", DEFAULT_LIMIT), where="defaults.limit")

    entries = raw.get("targets")
    if not isinstance(entries, list) or not entries:
        raise TargetsError("Targets file must contain a non-empty 'targets' array.")

    known = {c.lower() for c in known_categories} if known_categories is not None else None

    targets: list[Target] = []
    seen: set[str] = set()

    for index, entry in enumerate(entries):
        where = f"targets[{index}]"
        if not isinstance(entry, dict):
            raise TargetsError(f"{where} must be an object.")

        area = entry.get("area")
        if not isinstance(area, str) or not area.strip():
            raise TargetsError(f"{where}.area must be a non-empty string.")
        area = area.strip()

        categories = entry.get("categories")
        if not isinstance(categories, list) or not categories:
            raise TargetsError(f"{where}.categories must be a non-empty array.")

        limit = _coerce_limit(entry.get("limit", default_limit), where=f"{where}.limit")

        for category in categories:
            if not isinstance(category, str) or not category.strip():
                raise TargetsError(f"{where}.categories entries must be non-empty strings.")
            category = category.strip()

            # A raw "key=value" selector is passed through untouched — the provider accepts it and
            # validating it here would duplicate (and eventually contradict) the provider's rules.
            if known is not None and "=" not in category and category.lower() not in known:
                raise TargetsError(
                    f"{where}: unknown category '{category}'. "
                    f"Known: {', '.join(sorted(known))} (or a raw 'key=value' selector)."
                )

            target = Target(area=area, category=category, limit=limit)
            if target.key in seen:
                # Duplicates would give one pair extra turns in the rotation, quietly starving others.
                raise TargetsError(f"{where}: duplicate target '{target.key}'.")
            seen.add(target.key)
            targets.append(target)

    return targets


def _coerce_limit(value: Any, *, where: str) -> int:
    if isinstance(value, bool) or not isinstance(value, int):
        raise TargetsError(f"{where} must be an integer.")
    if value < 1:
        raise TargetsError(f"{where} must be >= 1.")
    if value > MAX_LIMIT:
        raise TargetsError(f"{where} must be <= {MAX_LIMIT} (got {value}).")
    return value


def targets_per_run(raw_default: Any = None, override: int | None = None) -> int:
    """How many pairs one scheduled run may touch.

    Precedence: an explicit `--targets` override, else `defaults.targets_per_run` from the targets
    file, else {@see DEFAULT_TARGETS_PER_RUN}. Clamped to {@see MAX_TARGETS_PER_RUN} so neither a
    flag typo nor a config typo can turn one execution into a full sweep.
    """
    value = override if override is not None else raw_default
    if value is None:
        value = DEFAULT_TARGETS_PER_RUN

    if isinstance(value, bool) or not isinstance(value, int) or value < 1:
        raise TargetsError(f"targets per run must be an integer >= 1 (got {value!r}).")

    return min(value, MAX_TARGETS_PER_RUN)


def configured_targets_per_run(path: Path | str | None = None) -> int | None:
    """`defaults.targets_per_run` from the targets file, if it sets one. None otherwise."""
    file = Path(path) if path is not None else DEFAULT_TARGETS_FILE
    if not file.exists():
        return None
    try:
        raw = json.loads(file.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, OSError):
        return None
    if not isinstance(raw, dict) or not isinstance(raw.get("defaults"), dict):
        return None
    value = raw["defaults"].get("targets_per_run")
    return value if isinstance(value, int) and not isinstance(value, bool) else None


def load_state(path: Path | str | None = None) -> dict:
    """Read the rotation cursor. A missing or corrupt file starts from zero rather than failing.

    State is a scheduling convenience, not business data: losing it re-runs a pair sooner than
    planned, which the CRM's place_id/email dedup makes harmless. Refusing to run because a cursor
    file was truncated would be the worse failure.
    """
    file = Path(path) if path is not None else DEFAULT_STATE_FILE

    if not file.exists():
        return {"cursor": 0, "last_run": {}}

    try:
        data = json.loads(file.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, OSError):
        return {"cursor": 0, "last_run": {}}

    if not isinstance(data, dict):
        return {"cursor": 0, "last_run": {}}

    cursor = data.get("cursor", 0)
    last_run = data.get("last_run", {})

    return {
        "cursor": cursor if isinstance(cursor, int) and cursor >= 0 else 0,
        "last_run": last_run if isinstance(last_run, dict) else {},
    }


def save_state(state: dict, path: Path | str | None = None) -> None:
    """Persist the cursor. Best-effort: a write failure must not fail an otherwise good run."""
    file = Path(path) if path is not None else DEFAULT_STATE_FILE
    try:
        file.write_text(json.dumps(state, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    except OSError:
        pass


def select(targets: list[Target], state: dict, count: int) -> list[Target]:
    """The next ``count`` targets in round-robin order, starting at the stored cursor.

    Round-robin rather than "least recently run" on purpose: it needs only an integer, it is
    deterministic and trivially testable, and it guarantees the property that actually matters —
    **no pair is queried twice until every other pair has had a turn.**

    Wraps when it reaches the end. Asking for more than exist returns the whole list once, never a
    pair repeated within a single run.
    """
    if not targets or count < 1:
        return []

    total = len(targets)
    start = state.get("cursor", 0) % total
    take = min(count, total)

    return [targets[(start + offset) % total] for offset in range(take)]


def advance(state: dict, targets: list[Target], selected: list[Target]) -> dict:
    """Move the cursor past what just ran and stamp each pair's last run.

    Returns a NEW dict; the caller decides whether to persist it. That is what lets ``--print-only``
    preview a rotation without consuming a turn.
    """
    total = len(targets) or 1
    cursor = (state.get("cursor", 0) + len(selected)) % total

    last_run = dict(state.get("last_run", {}))
    stamp = datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")
    for target in selected:
        last_run[target.key] = stamp

    return {"cursor": cursor, "last_run": last_run}
