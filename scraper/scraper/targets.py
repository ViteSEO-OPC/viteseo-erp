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

**Geographic groups (Phase 1).** A target may declare a ``group`` — an island group in practice
(``luzon`` / ``visayas`` / ``mindanao``), but the vocabulary is the FILE's, not this module's: no
group name is hardcoded here, exactly as no category word is. {@see select} then interleaves the
groups instead of walking the file in order, so one scheduled run spreads its turns across every
group rather than draining one city before another is ever visited.

A file that declares no groups behaves EXACTLY as it did before: every target lands in one implicit
group and interleaving degenerates to the original round-robin. That equivalence is the safety
property of this change, and it is what a test asserts.
"""

from __future__ import annotations

import json
from collections import OrderedDict
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable

from scraper.target_sources import FileTargetSource, TargetSource, TargetSourceError

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


#: The group a target belongs to when the file does not say. Every pre-group targets file therefore
#: parses into ONE group, which is what makes interleaving a no-op for it — see the module docstring.
DEFAULT_GROUP = "default"


class TargetsError(ValueError):
    """The targets file is missing, malformed, or names something the provider cannot discover."""


@dataclass(frozen=True)
class Target:
    """One unit of scheduled work: discover ``category`` businesses in ``area``, at most ``limit``."""

    area: str
    category: str
    limit: int
    #: Rotation group (an island group in the shipped file). DEFAULTED, so every existing
    #: construction site — tests included — keeps working: adding a dimension to the rotation must
    #: not become a breaking change to the Target contract.
    group: str = DEFAULT_GROUP

    @property
    def key(self) -> str:
        """Stable identity for the state file.

        Keyed by VALUE, not by list position, so reordering or inserting entries in the targets file
        never mis-attributes a pair's history to a different pair.
        """
        return f"{self.area}|{self.category}"

    def __str__(self) -> str:  # pragma: no cover - display only
        # The group is appended only when the file actually declared one, so a pre-group file's log
        # output stays byte-identical to what operators (and any log grep) already expect.
        suffix = "" if self.group == DEFAULT_GROUP else f" [{self.group}]"

        return f"{self.category} in {self.area} (limit {self.limit}){suffix}"


def load_targets(
    path: Path | str | None = None,
    *,
    known_categories: Iterable[str] | None = None,
    source: "TargetSource | None" = None,
) -> list[Target]:
    """Parse the targets file into a flat, ordered list of ``(area, category)`` pairs.

    Validation is strict and fails the whole run rather than silently skipping entries: a scheduled
    job nobody is watching must not quietly discover less than it was configured to.

    :param known_categories: when given, every category is checked against it up front, so a typo
        surfaces at parse time instead of midway through a run that has already committed leads.
    """
    # THE SEAM, MADE EXPLICIT. A source decides only WHERE the document comes from; everything below
    # this line — validation, defaults, grouping, rotation — is unchanged and unaware of the origin.
    # That is what lets a CRM-managed registry replace the file without touching scheduling logic.
    reader = source if source is not None else FileTargetSource(
        Path(path) if path is not None else DEFAULT_TARGETS_FILE
    )

    try:
        raw: Any = reader.load()
    except TargetSourceError as exc:
        # Re-raised as this module's error so every existing caller's `except TargetsError` still
        # catches a source failure. One failure vocabulary, whatever the origin.
        raise TargetsError(str(exc)) from exc

    if not isinstance(raw, dict):
        raise TargetsError(f"Targets document must be a JSON object, got {type(raw).__name__}.")

    defaults = raw.get("defaults", {})
    if not isinstance(defaults, dict):
        raise TargetsError("Targets file 'defaults' must be an object.")

    default_limit = _coerce_limit(defaults.get("limit", DEFAULT_LIMIT), where="defaults.limit")
    default_group = _coerce_group(defaults.get("group", DEFAULT_GROUP), where="defaults.group")

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
        group = _coerce_group(entry.get("group", default_group), where=f"{where}.group")

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

            target = Target(area=area, category=category, limit=limit, group=group)
            if target.key in seen:
                # Duplicates would give one pair extra turns in the rotation, quietly starving others.
                raise TargetsError(f"{where}: duplicate target '{target.key}'.")
            seen.add(target.key)
            targets.append(target)

    return targets


def _coerce_group(value: Any, *, where: str) -> str:
    """Validate a group NAME without owning a group VOCABULARY.

    Deliberately no whitelist of ``luzon``/``visayas``/``mindanao``. The set of groups is a property
    of the targets file, exactly as the set of areas is; hardcoding the three island groups here
    would mean a fourth (or a "metro-manila" split) needs a Python change, which is precisely the
    coupling {@see load_targets}'s docstring exists to avoid. Shape is checked; meaning is not.

    Normalized to lower case so ``Luzon`` and ``luzon`` are one group rather than two rotations.
    """
    if not isinstance(value, str) or not value.strip():
        raise TargetsError(f"{where} must be a non-empty string.")

    return value.strip().lower()


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


def configured_targets_per_run(
    path: Path | str | None = None,
    *,
    source: "TargetSource | None" = None,
) -> int | None:
    """`defaults.targets_per_run` from the targets document, if it sets one. None otherwise.

    Reads through the same pluggable source as {@see load_targets}, so a managed registry configures
    the run size exactly as the file does. Any failure answers None — the caller then falls back to
    its own default, which is the pre-existing behaviour for a missing or corrupt file.
    """
    reader = source if source is not None else FileTargetSource(
        Path(path) if path is not None else DEFAULT_TARGETS_FILE
    )
    try:
        raw = reader.load()
    except (TargetSourceError, OSError):
        return None
    if not isinstance(raw, dict) or not isinstance(raw.get("defaults"), dict):
        return None
    value = raw["defaults"].get("targets_per_run")
    return value if isinstance(value, int) and not isinstance(value, bool) else None


def _empty_state() -> dict:
    """A rotation that has not started. ONE shape for every state a run can begin from — missing
    file, corrupt file, wrong type — so no caller has to ask which of them it got."""
    return {"cursor": 0, "cursors": {}, "last_run": {}}


def load_state(path: Path | str | None = None) -> dict:
    """Read the rotation cursor. A missing or corrupt file starts from zero rather than failing.

    State is a scheduling convenience, not business data: losing it re-runs a pair sooner than
    planned, which the CRM's place_id/email dedup makes harmless. Refusing to run because a cursor
    file was truncated would be the worse failure.
    """
    file = Path(path) if path is not None else DEFAULT_STATE_FILE

    if not file.exists():
        return _empty_state()

    try:
        data = json.loads(file.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, OSError):
        return _empty_state()

    if not isinstance(data, dict):
        return _empty_state()

    cursor = data.get("cursor", 0)
    last_run = data.get("last_run", {})
    cursors = data.get("cursors", {})

    return {
        # LEGACY CURSOR, STILL READ AND STILL WRITTEN. A state file written by the pre-group code
        # holds only this integer; it seeds the groups on the FIRST grouped run and is then ignored
        # in favour of the per-group cursors ({@see _start_for} explains why it must stop being
        # consulted). It is still written back by {@see advance}, which is what lets a rollback to
        # the previous code resume rather than restart.
        "cursor": cursor if isinstance(cursor, int) and cursor >= 0 else 0,
        # Per-group cursors. Unreadable entries are dropped individually rather than failing the
        # run — the same "state is a scheduling convenience, not business data" rule as above.
        "cursors": {
            str(name): position
            for name, position in cursors.items()
            if isinstance(position, int) and not isinstance(position, bool) and position >= 0
        }
        if isinstance(cursors, dict)
        else {},
        "last_run": last_run if isinstance(last_run, dict) else {},
    }


def save_state(state: dict, path: Path | str | None = None) -> None:
    """Persist the cursor. Best-effort: a write failure must not fail an otherwise good run."""
    file = Path(path) if path is not None else DEFAULT_STATE_FILE
    try:
        file.write_text(json.dumps(state, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    except OSError:
        pass


def grouped(targets: list[Target]) -> "OrderedDict[str, list[Target]]":
    """Targets bucketed by group, groups in FIRST-APPEARANCE order, pairs in file order.

    File order is the tie-break for both levels so the rotation stays a property of the file a human
    reviewed, not of dict iteration or of a sort nobody asked for.
    """
    buckets: OrderedDict[str, list[Target]] = OrderedDict()
    for target in targets:
        buckets.setdefault(target.group, []).append(target)

    return buckets


def _start_for(state: dict, group: str, size: int) -> int:
    """Where ``group``'s rotation resumes.

    Precedence: the group's own cursor, else — ONLY while no group has one yet — the legacy
    single-integer cursor, else zero.

    THE "ONLY WHILE NONE EXIST" CLAUSE IS LOAD-BEARING. Seeding from the legacy cursor is how the
    first grouped run continues a pre-group rotation instead of sending every group back to its
    first pair. But once per-group cursors are being maintained, that same fallback would hand a
    group that simply has not run yet an unrelated aggregate position — a run bounded below the
    group count would then skip pairs in the groups it never visited. After the upgrade the legacy
    number describes no group, so it must stop being consulted.
    """
    cursors = state.get("cursors") or {}
    legacy = state.get("cursor", 0) if not cursors else 0
    position = cursors.get(group, legacy)
    if not isinstance(position, int) or isinstance(position, bool) or position < 0:
        position = 0

    return position % size if size else 0


def select(targets: list[Target], state: dict, count: int) -> list[Target]:
    """The next ``count`` targets, interleaved across groups, each group at its own cursor.

    Round-robin rather than "least recently run" on purpose: it needs only an integer per group, it
    is deterministic and trivially testable, and it guarantees the property that actually matters —
    **no pair is queried twice until every other pair IN ITS GROUP has had a turn.**

    INTERLEAVED, WHICH IS THE WHOLE POINT OF GROUPS. One pair is taken from each group in turn, so a
    three-target run over three island groups spends one turn on each rather than three on whichever
    group happens to sit first in the file. Without this a national target list would discover Luzon
    for weeks before Mindanao was visited once — the exhaustion pattern in miniature.

    A SINGLE-GROUP FILE IS UNCHANGED. With one group the loop below degenerates to exactly the old
    expression, `targets[(start + offset) % total]`, which is why a pre-group file's rotation is
    byte-identical.

    Wraps when a group reaches its end. Asking for more than exist returns the whole list once, never
    a pair repeated within a single run.
    """
    if not targets or count < 1:
        return []

    buckets = grouped(targets)
    starts = {name: _start_for(state, name, len(pairs)) for name, pairs in buckets.items()}
    taken: dict[str, int] = {name: 0 for name in buckets}

    selected: list[Target] = []
    take = min(count, len(targets))

    # Cycle the groups until the run is full or every group has given each of its pairs once. The
    # `progressed` guard is what terminates the loop when the groups are uneven — a group with two
    # pairs simply stops contributing while a group with nine keeps going.
    while len(selected) < take:
        progressed = False
        for name, pairs in buckets.items():
            if len(selected) >= take:
                break
            if taken[name] >= len(pairs):
                continue
            selected.append(pairs[(starts[name] + taken[name]) % len(pairs)])
            taken[name] += 1
            progressed = True
        if not progressed:  # pragma: no cover - `take` already bounds this; belt and braces
            break

    return selected


def advance(state: dict, targets: list[Target], selected: list[Target]) -> dict:
    """Move each group's cursor past what just ran and stamp each pair's last run.

    Returns a NEW dict; the caller decides whether to persist it. That is what lets ``--print-only``
    preview a rotation without consuming a turn.

    A GROUP THAT DID NOT RUN KEEPS ITS PLACE. Cursors are copied forward and only the groups that
    actually contributed to this run are moved, so a run bounded to fewer targets than there are
    groups cannot skip a group's turn — it simply reaches it next time.
    """
    buckets = grouped(targets)
    counts: dict[str, int] = {}
    for target in selected:
        counts[target.group] = counts.get(target.group, 0) + 1

    cursors = dict(state.get("cursors") or {})
    for name, ran in counts.items():
        size = len(buckets.get(name, []))
        if size:
            cursors[name] = (_start_for(state, name, size) + ran) % size

    last_run = dict(state.get("last_run", {}))
    stamp = datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")
    for target in selected:
        last_run[target.key] = stamp

    # The legacy flat cursor is still maintained, and only for the DEFAULT group — the shape the
    # pre-group code understands. For a single-group file it is the whole rotation, so rolling this
    # change back resumes exactly where the grouped run left off; for a grouped file the older code
    # would restart, which the self-healing state contract already permits.
    default_size = len(buckets.get(DEFAULT_GROUP, []))
    legacy_cursor = (
        cursors.get(DEFAULT_GROUP, 0)
        if default_size
        else (state.get("cursor", 0) + len(selected)) % (len(targets) or 1)
    )

    return {"cursor": legacy_cursor, "cursors": cursors, "last_run": last_run}
