"""Scheduled discovery (``discover-all``) — parsing, rotation, bounds, and write-safety.

Automation changes the operational model from human-triggered to unattended, so the properties that
were previously guaranteed by a human typing the command now have to be guaranteed by tests:

  * a run is BOUNDED — targets per run and leads per target both capped
  * a run is DRY by default — ``--commit`` is still the only thing that persists
  * a failing target cannot abort the run or block the others
  * the discovery CONTRACT is unchanged — same normalized fit-data, same ingest call

Nothing here touches the network: the provider and the ingest client are replaced with fakes, in the
same style as ``test_write_safety.py``.
"""

from __future__ import annotations

import json

import pytest

from scraper import cli
from scraper.targets import (
    MAX_LIMIT,
    Target,
    TargetsError,
    advance,
    load_state,
    load_targets,
    save_state,
    select,
)


# ── Fakes ───────────────────────────────────────────────────────────────────────────────────────


class FakeProvider:
    """Records every (area, category, limit) it was asked for; returns nothing (no normalization)."""

    name = "fakeprovider"
    calls: list[tuple[str, str, int]] = []

    def discover(self, *, area: str, category: str, limit: int) -> list:
        FakeProvider.calls.append((area, category, limit))
        return []

    def close(self) -> None:  # pragma: no cover - trivial
        pass


class ExplodingProvider(FakeProvider):
    """Fails for one specific area so failure isolation can be observed."""

    boom_area = "Boomtown, Philippines"

    def discover(self, *, area: str, category: str, limit: int) -> list:
        FakeProvider.calls.append((area, category, limit))
        if area == self.boom_area:
            raise RuntimeError("Nominatim could not resolve area")
        return []


class RecordingIngestClient:
    """Captures ingest calls instead of performing HTTP."""

    calls: list[dict] = []

    def __init__(self, *, base_url: str, token: str, user_agent: str) -> None:
        pass

    def ingest(self, businesses, *, dry_run: bool) -> dict:
        RecordingIngestClient.calls.append(
            {"dry_run": dry_run, "payloads": [b.to_payload() for b in businesses]}
        )
        return {"summary": {"total": len(businesses), "valid": 0, "duplicates": 0}, "importedCount": 0}

    def close(self) -> None:  # pragma: no cover - trivial
        pass


@pytest.fixture(autouse=True)
def _reset_fakes():
    FakeProvider.calls = []
    RecordingIngestClient.calls = []
    yield


@pytest.fixture
def targets_file(tmp_path):
    """Two areas × mixed categories → four ordered pairs."""
    path = tmp_path / "discovery-targets.json"
    path.write_text(
        json.dumps(
            {
                "defaults": {"limit": 10},
                "targets": [
                    {"area": "Alpha City, Philippines", "categories": ["pharmacy", "dentist"]},
                    {"area": "Beta City, Philippines", "categories": ["cafe", "salon"]},
                ],
            }
        ),
        encoding="utf-8",
    )
    return path


def _patch(monkeypatch, provider=FakeProvider, token: str = "test-token-not-real") -> None:
    monkeypatch.setattr(cli, "get_provider", lambda config: provider())
    monkeypatch.setattr(cli, "CrmIngestClient", RecordingIngestClient)
    monkeypatch.setenv("CRM_TOKEN", token)


# ── Target parsing ──────────────────────────────────────────────────────────────────────────────


def test_targets_expand_to_one_pair_per_category(targets_file):
    targets = load_targets(targets_file)

    assert [(t.area, t.category) for t in targets] == [
        ("Alpha City, Philippines", "pharmacy"),
        ("Alpha City, Philippines", "dentist"),
        ("Beta City, Philippines", "cafe"),
        ("Beta City, Philippines", "salon"),
    ]
    assert all(t.limit == 10 for t in targets)  # fixture sets defaults.limit = 10


def test_a_per_entry_limit_overrides_the_default(tmp_path):
    path = tmp_path / "t.json"
    path.write_text(
        json.dumps(
            {
                "defaults": {"limit": 10},
                "targets": [{"area": "A", "categories": ["cafe"], "limit": 3}],
            }
        ),
        encoding="utf-8",
    )

    assert load_targets(path)[0].limit == 3


def test_the_shipped_targets_file_parses(tmp_path):
    """The version-controlled file must itself be valid — a typo there breaks every scheduled run."""
    from scraper.targets import DEFAULT_TARGETS_FILE

    targets = load_targets(DEFAULT_TARGETS_FILE)

    assert targets, "the shipped targets file must define at least one target"
    assert all(t.limit <= MAX_LIMIT for t in targets)


@pytest.mark.parametrize(
    "payload, fragment",
    [
        ({"targets": []}, "non-empty 'targets'"),
        ({"targets": [{"categories": ["cafe"]}]}, "area"),
        ({"targets": [{"area": "A", "categories": []}]}, "categories"),
        ({"targets": [{"area": "A", "categories": ["cafe"], "limit": 0}]}, ">= 1"),
        ({"targets": [{"area": "A", "categories": ["cafe"], "limit": 999}]}, f"<= {MAX_LIMIT}"),
        ({"targets": [{"area": "A", "categories": ["cafe", "cafe"]}]}, "duplicate"),
    ],
)
def test_malformed_targets_fail_loudly(tmp_path, payload, fragment):
    path = tmp_path / "bad.json"
    path.write_text(json.dumps(payload), encoding="utf-8")

    with pytest.raises(TargetsError) as exc:
        load_targets(path)

    assert fragment in str(exc.value)


def test_missing_and_unparseable_files_fail_loudly(tmp_path):
    with pytest.raises(TargetsError):
        load_targets(tmp_path / "nope.json")

    bad = tmp_path / "bad.json"
    bad.write_text("{not json", encoding="utf-8")
    with pytest.raises(TargetsError):
        load_targets(bad)


# ── Rotation ────────────────────────────────────────────────────────────────────────────────────


def _pairs(targets):
    return [(t.area.split(",")[0], t.category) for t in targets]


def test_rotation_visits_every_target_before_repeating(targets_file):
    targets = load_targets(targets_file)
    state = {"cursor": 0, "last_run": {}}

    seen = []
    for _ in range(len(targets)):
        picked = select(targets, state, 1)
        seen.extend(picked)
        state = advance(state, targets, picked)

    assert len(seen) == len(targets)
    assert len({t.key for t in seen}) == len(targets), "a target was repeated before the others ran"


def test_rotation_wraps_back_to_the_start(targets_file):
    targets = load_targets(targets_file)
    state = {"cursor": len(targets) - 1, "last_run": {}}

    picked = select(targets, state, 1)
    assert picked[0].key == targets[-1].key

    state = advance(state, targets, picked)
    assert state["cursor"] == 0
    assert select(targets, state, 1)[0].key == targets[0].key


def test_selecting_more_than_exist_returns_each_once(targets_file):
    targets = load_targets(targets_file)

    picked = select(targets, {"cursor": 0, "last_run": {}}, 99)

    assert len(picked) == len(targets)
    assert len({t.key for t in picked}) == len(targets), "no pair may repeat within one run"


def test_advance_stamps_last_run_per_pair(targets_file):
    targets = load_targets(targets_file)
    picked = select(targets, {"cursor": 0, "last_run": {}}, 2)

    state = advance({"cursor": 0, "last_run": {}}, targets, picked)

    assert set(state["last_run"]) == {t.key for t in picked}
    assert state["cursor"] == 2


def test_state_survives_a_round_trip_and_tolerates_corruption(tmp_path):
    path = tmp_path / "state.json"

    save_state({"cursor": 3, "last_run": {"A|cafe": "2026-01-01T00:00:00Z"}}, path)
    assert load_state(path)["cursor"] == 3

    path.write_text("{corrupt", encoding="utf-8")
    # Asserted by MEANING rather than by dict identity: a healed state is "rotation at the start,
    # no history", and that is what must survive corruption. Pinning the exact key set made the
    # test fail when per-group cursors were added, which is not a behaviour change.
    healed = load_state(path)
    assert healed["cursor"] == 0, "corruption must not fail the run"
    assert healed["cursors"] == {}
    assert healed["last_run"] == {}

    assert load_state(tmp_path / "absent.json")["cursor"] == 0


# ── Bounds ──────────────────────────────────────────────────────────────────────────────────────


def test_default_run_uses_the_configured_targets_per_run(monkeypatch, targets_file, tmp_path):
    """The fixture file sets no targets_per_run, so the built-in default applies."""
    from scraper.targets import DEFAULT_TARGETS_PER_RUN

    _patch(monkeypatch)

    rc = cli.main(
        [
            "discover-all",
            "--targets-file", str(targets_file),
            "--state-file", str(tmp_path / "s.json"),
        ]
    )

    assert rc == 0
    assert len(FakeProvider.calls) == DEFAULT_TARGETS_PER_RUN


def test_targets_flag_bounds_the_run(monkeypatch, targets_file, tmp_path):
    _patch(monkeypatch)

    cli.main(
        [
            "discover-all", "--targets", "2",
            "--targets-file", str(targets_file),
            "--state-file", str(tmp_path / "s.json"),
        ]
    )

    assert len(FakeProvider.calls) == 2


def test_the_per_target_limit_is_passed_to_the_provider(monkeypatch, targets_file, tmp_path):
    _patch(monkeypatch)

    cli.main(
        [
            "discover-all",
            "--targets-file", str(targets_file),
            "--state-file", str(tmp_path / "s.json"),
        ]
    )

    assert FakeProvider.calls[0][2] == 10, "limit comes from the targets file"


def test_limit_flag_overrides_the_file(monkeypatch, targets_file, tmp_path):
    _patch(monkeypatch)

    cli.main(
        [
            "discover-all", "--limit", "3",
            "--targets-file", str(targets_file),
            "--state-file", str(tmp_path / "s.json"),
        ]
    )

    assert FakeProvider.calls[0][2] == 3


def test_consecutive_runs_advance_through_the_rotation(monkeypatch, targets_file, tmp_path):
    _patch(monkeypatch)
    state = tmp_path / "s.json"
    args = ["discover-all", "--targets-file", str(targets_file), "--state-file", str(state)]

    # One target per run makes the stepping observable; the rotation rule is independent of batch size.
    args = args + ["--targets", "1"]
    cli.main(args)
    cli.main(args)
    cli.main(args)

    visited = [(a, c) for a, c, _ in FakeProvider.calls]
    assert len(set(visited)) == 3, "each run must take a different target"


# ── Dry-run safety ──────────────────────────────────────────────────────────────────────────────


def test_discover_all_without_commit_sends_dry_run(monkeypatch, targets_file, tmp_path):
    _patch(monkeypatch)
    monkeypatch.setattr(cli, "normalize", lambda raw, source: raw)
    monkeypatch.setattr(
        FakeProvider, "discover", lambda self, *, area, category, limit: [_stub_business()]
    )

    cli.main(
        [
            "discover-all",
            "--targets-file", str(targets_file),
            "--state-file", str(tmp_path / "s.json"),
        ]
    )

    assert RecordingIngestClient.calls, "the ingest must be reached so the CRM can validate"
    assert all(c["dry_run"] is True for c in RecordingIngestClient.calls)


def test_discover_all_with_commit_sends_dry_run_false(monkeypatch, targets_file, tmp_path):
    _patch(monkeypatch)
    monkeypatch.setattr(cli, "normalize", lambda raw, source: raw)
    monkeypatch.setattr(
        FakeProvider, "discover", lambda self, *, area, category, limit: [_stub_business()]
    )

    cli.main(
        [
            "discover-all", "--commit",
            "--targets-file", str(targets_file),
            "--state-file", str(tmp_path / "s.json"),
        ]
    )

    assert all(c["dry_run"] is False for c in RecordingIngestClient.calls)


def test_print_only_never_reaches_the_crm_and_does_not_consume_a_turn(
    monkeypatch, targets_file, tmp_path
):
    _patch(monkeypatch)
    state = tmp_path / "s.json"

    cli.main(
        [
            "discover-all", "--print-only",
            "--targets-file", str(targets_file),
            "--state-file", str(state),
        ]
    )

    assert RecordingIngestClient.calls == [], "--print-only must not contact the CRM"
    assert not state.exists() or load_state(state)["cursor"] == 0, (
        "a preview must leave the rotation where it was"
    )


# ── Failure isolation ───────────────────────────────────────────────────────────────────────────


def test_one_failing_target_does_not_stop_the_others(monkeypatch, tmp_path):
    path = tmp_path / "t.json"
    path.write_text(
        json.dumps(
            {
                "defaults": {"limit": 5},
                "targets": [
                    {"area": ExplodingProvider.boom_area, "categories": ["cafe"]},
                    {"area": "Calm City, Philippines", "categories": ["cafe"]},
                ],
            }
        ),
        encoding="utf-8",
    )
    _patch(monkeypatch, provider=ExplodingProvider)

    rc = cli.main(
        [
            "discover-all", "--targets", "2",
            "--targets-file", str(path),
            "--state-file", str(tmp_path / "s.json"),
        ]
    )

    assert len(FakeProvider.calls) == 2, "the second target still ran"
    assert rc == 0, "partial success is success — the scheduler must not alarm"


def test_a_run_where_every_target_fails_reports_failure(monkeypatch, tmp_path):
    path = tmp_path / "t.json"
    path.write_text(
        json.dumps(
            {"defaults": {"limit": 5}, "targets": [{"area": ExplodingProvider.boom_area, "categories": ["cafe"]}]}
        ),
        encoding="utf-8",
    )
    _patch(monkeypatch, provider=ExplodingProvider)

    rc = cli.main(
        [
            "discover-all",
            "--targets-file", str(path),
            "--state-file", str(tmp_path / "s.json"),
        ]
    )

    assert rc == 1


def test_a_bad_targets_file_fails_before_any_discovery(monkeypatch, tmp_path):
    bad = tmp_path / "bad.json"
    bad.write_text(json.dumps({"targets": []}), encoding="utf-8")
    _patch(monkeypatch)

    rc = cli.main(["discover-all", "--targets-file", str(bad), "--state-file", str(tmp_path / "s.json")])

    assert rc == 2
    assert FakeProvider.calls == [], "nothing may be discovered when configuration is invalid"


# ── Contract safety ─────────────────────────────────────────────────────────────────────────────


def _stub_business():
    from scraper.models import NormalizedBusiness

    return NormalizedBusiness(business_name="Stub Co", source="fakeprovider")


def test_the_ingest_payload_is_unchanged_fit_data(monkeypatch, targets_file, tmp_path):
    """Automation must not widen what the scraper writes: same allowlist as the manual path."""
    _patch(monkeypatch)
    monkeypatch.setattr(cli, "normalize", lambda raw, source: raw)
    monkeypatch.setattr(
        FakeProvider, "discover", lambda self, *, area, category, limit: [_stub_business()]
    )

    cli.main(
        [
            "discover-all", "--commit",
            "--targets-file", str(targets_file),
            "--state-file", str(tmp_path / "s.json"),
        ]
    )

    payload = RecordingIngestClient.calls[0]["payloads"][0]
    forbidden = {"priority", "status", "email_outreach", "cold_calling", "score", "assigned_to"}
    assert not (forbidden & set(payload)), "engagement/CRM-decision fields must never be sent"
    assert payload["source"] == "fakeprovider"


def test_discover_all_reuses_the_single_discovery_path(monkeypatch, targets_file, tmp_path):
    """It must call run_discovery, not a second implementation that could drift from it."""
    seen: list[dict] = []

    def _spy(config, *, area, category, limit, commit, print_only=False, out=None):
        seen.append({"area": area, "category": category, "limit": limit, "commit": commit})
        return 0

    monkeypatch.setattr(cli, "run_discovery", _spy)
    monkeypatch.setenv("CRM_TOKEN", "test-token-not-real")

    cli.main(
        [
            "discover-all", "--commit", "--targets", "1",
            "--targets-file", str(targets_file),
            "--state-file", str(tmp_path / "s.json"),
        ]
    )

    assert len(seen) == 1
    assert seen[0]["commit"] is True
    assert seen[0]["limit"] == 10


# ── Volume / operational range ───────────────────────────────────────────────────────────────────


def test_default_limit_is_in_the_operational_range():
    """The pool must build continuously, so the default sits at the top of the 10-30 range."""
    from scraper.targets import DEFAULT_LIMIT

    assert 10 <= DEFAULT_LIMIT <= 30
    assert DEFAULT_LIMIT == 30, "30 is preferred over 10 — see the continuous-population requirement"


def test_a_run_processes_multiple_targets_by_default():
    from scraper.targets import DEFAULT_TARGETS_PER_RUN, targets_per_run

    assert DEFAULT_TARGETS_PER_RUN > 1, "one target per run would take weeks to complete a rotation"
    assert targets_per_run(None, override=None) == DEFAULT_TARGETS_PER_RUN


def test_targets_per_run_precedence_flag_beats_file_beats_default():
    from scraper.targets import DEFAULT_TARGETS_PER_RUN, targets_per_run

    assert targets_per_run(None, override=None) == DEFAULT_TARGETS_PER_RUN   # built-in
    assert targets_per_run(5, override=None) == 5                            # file
    assert targets_per_run(5, override=2) == 2                               # flag wins


def test_provider_protections_still_clamp_both_dimensions():
    """Configurability must not be able to remove the provider-etiquette ceilings."""
    from scraper.targets import MAX_LIMIT, MAX_TARGETS_PER_RUN, targets_per_run

    assert targets_per_run(9999, override=None) == MAX_TARGETS_PER_RUN
    assert targets_per_run(None, override=9999) == MAX_TARGETS_PER_RUN
    assert MAX_LIMIT == 50, "per-target ceiling is a provider protection, not a product knob"


def test_targets_per_run_rejects_nonsense():
    from scraper.targets import TargetsError, targets_per_run

    for bad in (0, -1, "3", True, 1.5):
        with pytest.raises(TargetsError):
            targets_per_run(None, override=bad)


def test_the_shipped_file_configures_the_preferred_range():
    from scraper.targets import DEFAULT_TARGETS_FILE, configured_targets_per_run, load_targets

    targets = load_targets(DEFAULT_TARGETS_FILE)
    assert all(t.limit == 30 for t in targets), "shipped defaults.limit should be 30"
    assert configured_targets_per_run(DEFAULT_TARGETS_FILE) == 3


def test_multiple_targets_run_in_one_execution(monkeypatch, targets_file, tmp_path):
    """End-to-end: one invocation advances several pairs, not just one."""
    _patch(monkeypatch)

    cli.main(
        [
            "discover-all", "--targets", "3",
            "--targets-file", str(targets_file),
            "--state-file", str(tmp_path / "s.json"),
        ]
    )

    assert len(FakeProvider.calls) == 3
    assert len({(a, c) for a, c, _ in FakeProvider.calls}) == 3, "three DISTINCT pairs"


# ── Automatic-discovery objective ────────────────────────────────────────────────────────────────


@pytest.fixture
def multi_area_file(tmp_path):
    """Several cities x several categories, incl. raw provider selectors and a per-entry limit."""
    path = tmp_path / "multi.json"
    path.write_text(
        json.dumps(
            {
                "defaults": {"limit": 30, "targets_per_run": 3},
                "targets": [
                    {"area": "Makati City, Philippines", "categories": ["pharmacy", "dentist"]},
                    {"area": "Quezon City, Philippines", "categories": ["restaurant", "salon"]},
                    {"area": "Cebu City, Philippines", "categories": ["hotel"]},
                    {"area": "Pasig City, Philippines", "categories": ["shop=pet"], "limit": 10},
                ],
            }
        ),
        encoding="utf-8",
    )
    return path


def test_config_supports_many_areas_and_many_categories(multi_area_file):
    targets = load_targets(multi_area_file)

    assert len({t.area for t in targets}) == 4, "multiple cities"
    assert len(targets) == 6, "one pair per (area, category)"
    assert any(t.category == "shop=pet" for t in targets), "raw provider selectors allowed"
    assert next(t for t in targets if t.category == "shop=pet").limit == 10, "per-entry limit"


def test_common_and_custom_categories_coexist(multi_area_file):
    """Friendly words and raw key=value selectors sit side by side — no allowlist blocks either."""
    categories = {t.category for t in load_targets(multi_area_file)}

    assert {"pharmacy", "restaurant"} <= categories   # common
    assert "shop=pet" in categories                   # custom/unique


def test_rotation_covers_every_target_then_wraps(multi_area_file):
    """The runner must not keep re-discovering the same target."""
    targets = load_targets(multi_area_file)
    state = {"cursor": 0, "last_run": {}}

    seen = []
    for _ in range(2):  # 2 runs x 3 targets = 6 = every pair
        picked = select(targets, state, 3)
        seen.extend(picked)
        state = advance(state, targets, picked)

    assert len({t.key for t in seen}) == len(targets), "every configured pair got a turn"

    # Third run wraps back to the first pair — the pool keeps growing, coverage keeps cycling.
    assert select(targets, state, 1)[0].key == targets[0].key


def test_adding_a_target_needs_no_python_change(tmp_path, monkeypatch):
    """A new city/category is a data edit; the runner picks it up on the next execution."""
    path = tmp_path / "t.json"
    path.write_text(
        json.dumps({"defaults": {"limit": 10}, "targets": [{"area": "A", "categories": ["cafe"]}]}),
        encoding="utf-8",
    )
    assert len(load_targets(path)) == 1

    path.write_text(
        json.dumps(
            {
                "defaults": {"limit": 10},
                "targets": [
                    {"area": "A", "categories": ["cafe"]},
                    {"area": "B", "categories": ["hotel", "gym"]},
                ],
            }
        ),
        encoding="utf-8",
    )

    assert len(load_targets(path)) == 3, "new targets are live without touching code"


def test_a_target_yielding_only_duplicates_does_not_block_the_others(monkeypatch, tmp_path):
    """The CRM dedups; a fully-duplicate target is a normal, successful, zero-import run."""
    path = tmp_path / "t.json"
    path.write_text(
        json.dumps(
            {
                "defaults": {"limit": 5},
                "targets": [
                    {"area": "Dup City", "categories": ["cafe"]},
                    {"area": "Fresh City", "categories": ["cafe"]},
                ],
            }
        ),
        encoding="utf-8",
    )

    class AllDuplicatesClient(RecordingIngestClient):
        def ingest(self, businesses, *, dry_run: bool) -> dict:
            RecordingIngestClient.calls.append({"dry_run": dry_run, "payloads": []})
            n = len(businesses)
            return {
                "summary": {"total": n, "valid": 0, "duplicates": n},
                "importedCount": 0,
            }

    def _discover_recording(self, *, area, category, limit):
        FakeProvider.calls.append((area, category, limit))
        return [_stub_business()]

    monkeypatch.setattr(cli, "get_provider", lambda config: FakeProvider())
    monkeypatch.setattr(cli, "CrmIngestClient", AllDuplicatesClient)
    monkeypatch.setattr(cli, "normalize", lambda raw, source: raw)
    monkeypatch.setattr(FakeProvider, "discover", _discover_recording)
    monkeypatch.setenv("CRM_TOKEN", "test-token-not-real")

    rc = cli.main(
        [
            "discover-all", "--commit", "--targets", "2",
            "--targets-file", str(path),
            "--state-file", str(tmp_path / "s.json"),
        ]
    )

    assert rc == 0, "duplicates are a normal outcome, not a failure"
    assert len(FakeProvider.calls) == 2, "the second target still ran"


def test_rerunning_the_same_schedule_is_safe(monkeypatch, multi_area_file, tmp_path):
    """Idempotency: repeated runs advance the rotation and never re-select within a cycle."""
    _patch(monkeypatch)
    state = tmp_path / "s.json"
    args = [
        "discover-all", "--targets", "3",
        "--targets-file", str(multi_area_file),
        "--state-file", str(state),
    ]

    cli.main(args)
    cli.main(args)

    visited = [(a, c) for a, c, _ in FakeProvider.calls]
    assert len(visited) == 6
    assert len(set(visited)) == 6, "a full cycle must not repeat a pair"


def test_the_cli_limit_flag_cannot_breach_the_provider_cap(monkeypatch, multi_area_file, tmp_path):
    """`--limit` is clamped like the file is — the cap protects shared OSM infrastructure."""
    _patch(monkeypatch)

    cli.main(
        [
            "discover-all", "--targets", "1", "--limit", "9999",
            "--targets-file", str(multi_area_file),
            "--state-file", str(tmp_path / "s.json"),
        ]
    )

    assert FakeProvider.calls[0][2] == MAX_LIMIT, "an absurd --limit must be clamped, not honoured"


def test_the_configured_range_is_accepted_end_to_end(monkeypatch, tmp_path):
    """10 (minimum) and 30 (preferred maximum) both pass validation and reach the provider."""
    for limit in (10, 30):
        path = tmp_path / f"t{limit}.json"
        path.write_text(
            json.dumps(
                {"defaults": {"limit": limit}, "targets": [{"area": "A", "categories": ["cafe"]}]}
            ),
            encoding="utf-8",
        )
        FakeProvider.calls = []
        _patch(monkeypatch)

        cli.main(
            [
                "discover-all", "--targets", "1",
                "--targets-file", str(path),
                "--state-file", str(tmp_path / f"s{limit}.json"),
            ]
        )

        assert FakeProvider.calls[0][2] == limit
