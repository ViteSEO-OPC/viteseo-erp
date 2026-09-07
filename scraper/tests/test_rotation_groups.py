"""Geographic rotation groups — fair interleaving across Luzon / Visayas / Mindanao.

The property these tests exist to protect is the one the flat rotation could not give: a bounded
nightly run must spread its turns ACROSS the island groups instead of draining whichever group sits
first in the targets file. Everything else about the rotation — the per-pair round-robin guarantee,
the caps, the self-healing state — must survive unchanged.

Nothing here touches the network or the CRM: this module tests parsing and selection only, which is
the whole reason ``targets.py`` is inert.
"""

from __future__ import annotations

import json

import pytest

from scraper.targets import (
    DEFAULT_GROUP,
    Target,
    TargetsError,
    advance,
    grouped,
    load_state,
    load_targets,
    save_state,
    select,
)


# ── Fixtures ────────────────────────────────────────────────────────────────────────────────────


def _write(tmp_path, payload) -> "object":
    path = tmp_path / "targets.json"
    path.write_text(json.dumps(payload), encoding="utf-8")
    return path


@pytest.fixture
def three_groups(tmp_path):
    """Three groups of two pairs each — the shape of the shipped matrix, small enough to reason about."""
    return _write(
        tmp_path,
        {
            "defaults": {"limit": 10},
            "targets": [
                {"group": "luzon", "area": "Makati City, Philippines", "categories": ["pharmacy", "dentist"]},
                {"group": "visayas", "area": "Cebu City, Philippines", "categories": ["pharmacy", "dentist"]},
                {"group": "mindanao", "area": "Davao City, Philippines", "categories": ["pharmacy", "dentist"]},
            ],
        },
    )


@pytest.fixture
def uneven_groups(tmp_path):
    """A deliberately lopsided file: one group with three pairs, one with a single pair."""
    return _write(
        tmp_path,
        {
            "defaults": {"limit": 10},
            "targets": [
                {"group": "luzon", "area": "Makati City, Philippines", "categories": ["pharmacy", "dentist", "clinic"]},
                {"group": "visayas", "area": "Cebu City, Philippines", "categories": ["pharmacy"]},
            ],
        },
    )


@pytest.fixture
def ungrouped(tmp_path):
    """A pre-group targets file — no `group` key anywhere. Must behave exactly as it always did."""
    return _write(
        tmp_path,
        {
            "defaults": {"limit": 10},
            "targets": [
                {"area": "Alpha City, Philippines", "categories": ["pharmacy", "dentist"]},
                {"area": "Beta City, Philippines", "categories": ["cafe", "salon"]},
            ],
        },
    )


def _empty() -> dict:
    return {"cursor": 0, "cursors": {}, "last_run": {}}


# ── Parsing ─────────────────────────────────────────────────────────────────────────────────────


def test_a_group_is_parsed_onto_every_pair_of_its_entry(three_groups):
    targets = load_targets(three_groups)

    assert [(t.group, t.category) for t in targets] == [
        ("luzon", "pharmacy"),
        ("luzon", "dentist"),
        ("visayas", "pharmacy"),
        ("visayas", "dentist"),
        ("mindanao", "pharmacy"),
        ("mindanao", "dentist"),
    ]


def test_a_file_without_groups_lands_in_one_default_group(ungrouped):
    assert {t.group for t in load_targets(ungrouped)} == {DEFAULT_GROUP}


def test_group_names_are_normalized_so_casing_cannot_split_a_rotation(tmp_path):
    path = _write(
        tmp_path,
        {
            "targets": [
                {"group": "Luzon", "area": "Makati City", "categories": ["pharmacy"]},
                {"group": " luzon ", "area": "Quezon City", "categories": ["cafe"]},
            ]
        },
    )

    assert {t.group for t in load_targets(path)} == {"luzon"}


def test_defaults_may_set_the_group_for_a_whole_file(tmp_path):
    path = _write(
        tmp_path,
        {
            "defaults": {"group": "luzon"},
            "targets": [
                {"area": "Makati City", "categories": ["pharmacy"]},
                {"group": "visayas", "area": "Cebu City", "categories": ["pharmacy"]},
            ],
        },
    )

    assert [t.group for t in load_targets(path)] == ["luzon", "visayas"]


@pytest.mark.parametrize("bad", [{"group": ""}, {"group": "   "}, {"group": 7}, {"group": None}])
def test_a_malformed_group_fails_loudly(tmp_path, bad):
    path = _write(tmp_path, {"targets": [{**bad, "area": "Makati City", "categories": ["pharmacy"]}]})

    with pytest.raises(TargetsError, match="group"):
        load_targets(path)


def test_the_same_pair_cannot_be_declared_in_two_groups(tmp_path):
    """Duplicate detection is by (area, category) — a pair in two groups would get two turns."""
    path = _write(
        tmp_path,
        {
            "targets": [
                {"group": "luzon", "area": "Makati City", "categories": ["pharmacy"]},
                {"group": "visayas", "area": "Makati City", "categories": ["pharmacy"]},
            ]
        },
    )

    with pytest.raises(TargetsError, match="duplicate"):
        load_targets(path)


# ── Fair interleaving ───────────────────────────────────────────────────────────────────────────


def test_a_default_run_takes_one_pair_from_each_group(three_groups):
    targets = load_targets(three_groups)

    picked = select(targets, _empty(), 3)

    assert [t.group for t in picked] == ["luzon", "visayas", "mindanao"]


def test_a_run_never_drains_one_group_before_visiting_another(three_groups):
    """The exhaustion pattern in miniature: flat order would have given Luzon both its pairs first."""
    targets = load_targets(three_groups)

    picked = select(targets, _empty(), 2)

    assert [t.group for t in picked] == ["luzon", "visayas"]


def test_consecutive_runs_advance_each_group_independently(three_groups):
    targets = load_targets(three_groups)
    state = _empty()

    first = select(targets, state, 3)
    state = advance(state, targets, first)
    second = select(targets, state, 3)

    assert [t.category for t in first] == ["pharmacy", "pharmacy", "pharmacy"]
    assert [t.category for t in second] == ["dentist", "dentist", "dentist"]
    assert [t.group for t in second] == ["luzon", "visayas", "mindanao"]


def test_every_pair_is_visited_before_any_repeats(three_groups):
    targets = load_targets(three_groups)
    state = _empty()

    seen = []
    for _ in range(2):  # two runs of three = the whole matrix
        picked = select(targets, state, 3)
        state = advance(state, targets, picked)
        seen.extend(t.key for t in picked)

    assert sorted(seen) == sorted(t.key for t in targets), "a pair was skipped or repeated"


def test_the_rotation_wraps_back_to_each_groups_first_pair(three_groups):
    targets = load_targets(three_groups)
    state = _empty()

    for _ in range(3):  # one more run than the matrix holds
        picked = select(targets, state, 3)
        state = advance(state, targets, picked)

    assert [t.category for t in select(targets, state, 3)] == ["dentist", "dentist", "dentist"]


def test_an_uneven_matrix_keeps_the_small_group_in_the_rotation(uneven_groups):
    """A group with one pair must not be starved by a group with three, nor repeat within a run."""
    targets = load_targets(uneven_groups)

    picked = select(targets, _empty(), 4)

    assert [(t.group, t.category) for t in picked] == [
        ("luzon", "pharmacy"),
        ("visayas", "pharmacy"),
        ("luzon", "dentist"),
        ("luzon", "clinic"),
    ]


def test_asking_for_more_than_exist_returns_each_pair_once(uneven_groups):
    targets = load_targets(uneven_groups)

    picked = select(targets, _empty(), 99)

    assert len(picked) == len(targets)
    assert len({t.key for t in picked}) == len(targets)


def test_a_group_that_did_not_run_keeps_its_place(three_groups):
    """A run bounded below the group count must not cost the unvisited groups their turn."""
    targets = load_targets(three_groups)

    state = advance(_empty(), targets, select(targets, _empty(), 1))  # luzon only

    assert state["cursors"] == {"luzon": 1}
    assert [t.group for t in select(targets, state, 3)] == ["luzon", "visayas", "mindanao"]
    assert [t.category for t in select(targets, state, 3)] == ["dentist", "pharmacy", "pharmacy"]


# ── Backwards compatibility ─────────────────────────────────────────────────────────────────────


def test_an_ungrouped_file_rotates_exactly_as_it_did_before(ungrouped):
    """The equivalence that makes this change safe to deploy against the live targets file."""
    targets = load_targets(ungrouped)
    state = _empty()

    order = []
    for _ in range(2):
        picked = select(targets, state, 2)
        state = advance(state, targets, picked)
        order.extend((t.area, t.category) for t in picked)

    assert order == [(t.area, t.category) for t in targets], "flat round-robin order changed"


def test_a_legacy_state_file_resumes_instead_of_restarting(ungrouped, tmp_path):
    """A production state file written by the pre-group code holds only `cursor`."""
    path = tmp_path / "state.json"
    path.write_text(json.dumps({"cursor": 2, "last_run": {}}), encoding="utf-8")
    targets = load_targets(ungrouped)

    picked = select(targets, load_state(path), 2)

    assert [(t.area, t.category) for t in picked] == [
        ("Beta City, Philippines", "cafe"),
        ("Beta City, Philippines", "salon"),
    ], "the legacy cursor was ignored and the rotation restarted"


def test_a_legacy_cursor_seeds_every_group_of_a_newly_grouped_file(three_groups, tmp_path):
    """Upgrading a live rotation must not be an error, whatever the old integer happened to be."""
    path = tmp_path / "state.json"
    path.write_text(json.dumps({"cursor": 5, "last_run": {}}), encoding="utf-8")
    targets = load_targets(three_groups)

    picked = select(targets, load_state(path), 3)

    assert [t.group for t in picked] == ["luzon", "visayas", "mindanao"]
    assert len({t.key for t in picked}) == 3


def test_advance_still_writes_a_legacy_cursor_for_an_ungrouped_file(ungrouped):
    """Rollback affordance: the previous code reads `cursor` and must resume, not restart."""
    targets = load_targets(ungrouped)

    state = advance(_empty(), targets, select(targets, _empty(), 2))

    assert state["cursor"] == 2


def test_state_round_trips_with_group_cursors(three_groups, tmp_path):
    path = tmp_path / "state.json"
    targets = load_targets(three_groups)

    state = advance(_empty(), targets, select(targets, _empty(), 3))
    save_state(state, path)

    assert load_state(path)["cursors"] == {"luzon": 1, "visayas": 1, "mindanao": 1}


def test_unreadable_group_cursors_are_dropped_not_fatal(tmp_path, three_groups):
    path = tmp_path / "state.json"
    path.write_text(
        json.dumps({"cursors": {"luzon": 1, "visayas": "nonsense", "mindanao": -4}, "last_run": {}}),
        encoding="utf-8",
    )

    assert load_state(path)["cursors"] == {"luzon": 1}
    assert len(select(load_targets(three_groups), load_state(path), 3)) == 3


# ── Grouping helper ─────────────────────────────────────────────────────────────────────────────


def test_grouped_preserves_first_appearance_and_file_order(three_groups):
    buckets = grouped(load_targets(three_groups))

    assert list(buckets) == ["luzon", "visayas", "mindanao"]
    assert [t.category for t in buckets["luzon"]] == ["pharmacy", "dentist"]


def test_grouping_is_not_a_sort(tmp_path):
    """Groups rotate in the order a human wrote them, never alphabetically."""
    path = _write(
        tmp_path,
        {
            "targets": [
                {"group": "visayas", "area": "Cebu City", "categories": ["pharmacy"]},
                {"group": "luzon", "area": "Makati City", "categories": ["pharmacy"]},
            ]
        },
    )

    assert list(grouped(load_targets(path))) == ["visayas", "luzon"]


# ── End to end through the CLI ──────────────────────────────────────────────────────────────────


def test_a_scheduled_run_discovers_one_area_per_group(monkeypatch, three_groups, tmp_path, capsys):
    """The whole point, exercised through `discover-all` itself rather than through `select` alone.

    Uses the same fake provider and fake ingest client as ``test_discover_all`` — no network, no CRM.
    """
    from scraper import cli
    from tests.test_discover_all import FakeProvider, RecordingIngestClient

    FakeProvider.calls = []
    monkeypatch.setattr(cli, "get_provider", lambda config: FakeProvider())
    monkeypatch.setattr(cli, "CrmIngestClient", RecordingIngestClient)
    monkeypatch.setenv("CRM_TOKEN", "test-token-not-real")

    rc = cli.main(
        [
            "discover-all",
            "--targets-file", str(three_groups),
            "--state-file", str(tmp_path / "s.json"),
        ]
    )

    assert rc == 0
    assert [area for area, _cat, _limit in FakeProvider.calls] == [
        "Makati City, Philippines",
        "Cebu City, Philippines",
        "Davao City, Philippines",
    ], "a scheduled run did not spread across the island groups"
    assert "luzon 1/2, visayas 1/2, mindanao 1/2" in capsys.readouterr().err


def test_a_second_scheduled_run_moves_every_group_forward(monkeypatch, three_groups, tmp_path):
    from scraper import cli
    from tests.test_discover_all import FakeProvider, RecordingIngestClient

    monkeypatch.setattr(cli, "get_provider", lambda config: FakeProvider())
    monkeypatch.setattr(cli, "CrmIngestClient", RecordingIngestClient)
    monkeypatch.setenv("CRM_TOKEN", "test-token-not-real")
    state = tmp_path / "s.json"
    args = ["discover-all", "--targets-file", str(three_groups), "--state-file", str(state)]

    FakeProvider.calls = []
    cli.main(args)
    cli.main(args)

    assert [cat for _area, cat, _limit in FakeProvider.calls] == [
        "pharmacy", "pharmacy", "pharmacy",   # run 1 — one per group
        "dentist", "dentist", "dentist",      # run 2 — each group advanced independently
    ]


# ── Provider protection is untouched ────────────────────────────────────────────────────────────


def test_groups_do_not_widen_any_cap(three_groups):
    """Grouping changes WHICH pairs run, never how many or how large — the caps are unchanged."""
    targets = load_targets(three_groups)

    assert len(select(targets, _empty(), 3)) == 3
    assert all(t.limit == 10 for t in select(targets, _empty(), 99))


def test_the_shipped_targets_file_still_parses_and_is_internally_consistent():
    """The live file must stay valid at every point of this change, grouped or not."""
    from scraper.targets import DEFAULT_TARGETS_FILE

    targets = load_targets(DEFAULT_TARGETS_FILE)

    assert targets, "the shipped targets file produced no targets"
    assert len({t.key for t in targets}) == len(targets), "duplicate pair in the shipped file"
    assert all(isinstance(t, Target) and t.group for t in targets)
