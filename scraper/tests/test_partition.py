"""Geographic partitioning: one scope becomes several bounded tasks — and knows no geography.

Pinned here:

  - **No place names anywhere.** The grid path divides four numbers. The only way a NAME reaches a
    task is by surviving a geocoder, so an area that does not resolve can never be searched.
  - **Cells tile the scope exactly** — no gaps, no overlap, nothing outside the original box.
  - **The limit is DIVIDED, never multiplied.** Partitioning changes where a run looks, not how much
    it may ingest, or the caps protecting shared infrastructure would stop meaning anything.
  - **Bounded by construction**, because an unbounded fan-out is what a crawler is.
  - **One cell is today's behaviour**, byte for byte.

No test touches the network: the geocoder is a stub.
"""

import pytest

from scraper.partition import (
    DEFAULT_CELLS,
    MAX_CELLS,
    DiscoveryTask,
    clamp_cells,
    grid_shape,
    partition_bbox,
    plan_tasks,
)

# A deliberately anonymous box. It is not anywhere — that is the point of this module.
BOX = (10.0, 120.0, 14.0, 124.0)


# --- clamping: a fan-out is bounded ---------------------------------------------------------------


@pytest.mark.parametrize(
    "value,expected",
    [
        (None, DEFAULT_CELLS),
        (0, 1),
        (-5, 1),
        (1, 1),
        (4, 4),
        (MAX_CELLS, MAX_CELLS),
        (MAX_CELLS + 1, MAX_CELLS),
        (10_000, MAX_CELLS),
        (True, DEFAULT_CELLS),  # bool is an int in Python; it is not a cell count.
        ("4", DEFAULT_CELLS),
    ],
)
def test_cells_are_clamped_never_rejected(value, expected):
    # An operational dial, not a correctness input: a typo should cost a smaller sweep than intended,
    # never fail a scheduled run that would otherwise have done useful work.
    assert clamp_cells(value) == expected


def test_a_partition_can_never_exceed_the_ceiling():
    assert len(partition_bbox(BOX, 10_000)) <= MAX_CELLS


# --- the grid: pure geometry ----------------------------------------------------------------------


def test_one_cell_is_the_untouched_scope():
    assert partition_bbox(BOX, 1) == [BOX]


def test_cells_are_as_square_as_possible():
    # A 1xN strip caps the same way one box does along its long axis, so the shape is derived rather
    # than tabulated: 9 must be 3x3, not 1x9.
    assert grid_shape(9) == (3, 3)
    assert grid_shape(4) == (2, 2)
    assert grid_shape(2) == (1, 2)


def test_cells_tile_the_scope_exactly():
    boxes = partition_bbox(BOX, 4)
    assert len(boxes) == 4

    south, west, north, east = BOX
    # Nothing outside the original scope, in any cell.
    for s, w, n, e in boxes:
        assert south <= s < n <= north
        assert west <= w < e <= east

    # The cells' combined area equals the scope's: no gaps and no overlap.
    scope_area = (north - south) * (east - west)
    covered = sum((n - s) * (e - w) for s, w, n, e in boxes)
    assert covered == pytest.approx(scope_area)


def test_a_degenerate_box_is_returned_whole_rather_than_divided():
    # A point cannot be meaningfully divided; returning it whole keeps the caller on the ordinary
    # single-task path instead of producing zero-area cells that could never match anything.
    point = (5.0, 100.0, 5.0, 100.0)
    assert partition_bbox(point, 4) == [point]


# --- planning tasks -------------------------------------------------------------------------------


def test_the_default_is_one_task_and_todays_behaviour():
    tasks = plan_tasks(area="Somewhere", category="cafe", limit=20)

    assert tasks == [DiscoveryTask(area="Somewhere", category="cafe", limit=20, bbox=None)]


def test_a_grid_divides_the_limit_rather_than_multiplying_it():
    tasks = plan_tasks(area="Somewhere", category="cafe", limit=20, cells=4, bbox=BOX)

    assert len(tasks) == 4
    # THE PROPERTY THAT KEEPS THE CAPS MEANINGFUL.
    assert sum(t.limit for t in tasks) == 20
    assert all(t.bbox is not None for t in tasks)
    assert all(t.area == "Somewhere" for t in tasks)


def test_every_task_may_discover_at_least_one():
    # A task allowed to discover nothing is a provider request spent for a guaranteed empty result.
    tasks = plan_tasks(area="Somewhere", category="cafe", limit=2, cells=4, bbox=BOX)

    assert all(t.limit >= 1 for t in tasks)


def test_a_grid_needs_a_box_and_degrades_to_one_task_without_one():
    tasks = plan_tasks(area="Somewhere", category="cafe", limit=20, cells=4, bbox=None)

    assert len(tasks) == 1
    assert tasks[0].bbox is None


# --- named sub-areas: proposals, never geography --------------------------------------------------


def test_named_sub_areas_must_survive_the_geocoder():
    # The ONLY path by which a suggested area reaches execution, and it cannot bypass the geocoder.
    resolved = {"Alpha": BOX, "Beta": (11.0, 121.0, 12.0, 122.0)}

    def geocode(name):
        if name not in resolved:
            raise ValueError(f"unresolvable: {name}")
        return resolved[name]

    tasks = plan_tasks(
        area="Scope",
        category="cafe",
        limit=30,
        sub_areas=["Alpha", "Ghost Town That Does Not Exist", "Beta"],
        geocode=geocode,
    )

    # The unresolvable proposal is DROPPED, never guessed at and never searched.
    assert [t.area for t in tasks] == ["Alpha", "Beta"]
    assert all(t.bbox is not None for t in tasks)
    assert sum(t.limit for t in tasks) == 30


def test_sub_areas_are_preferred_over_a_geometric_grid():
    # Named areas follow real administrative boundaries, which is how a provider's own data is
    # organised — so when both are available, names win.
    tasks = plan_tasks(
        area="Scope",
        category="cafe",
        limit=10,
        cells=4,
        bbox=BOX,
        sub_areas=["Alpha"],
        geocode=lambda name: BOX,
    )

    assert [t.area for t in tasks] == ["Alpha"]


def test_sub_areas_without_a_geocoder_are_ignored_entirely():
    # No geocoder means no way to validate a name, and an unvalidated name must never become a search.
    tasks = plan_tasks(area="Scope", category="cafe", limit=10, sub_areas=["Alpha", "Beta"])

    assert len(tasks) == 1
    assert tasks[0].area == "Scope"


def test_when_every_sub_area_fails_to_resolve_the_scope_is_still_searched():
    # Losing the scope entirely because a suggestion was wrong would make the optional layer capable
    # of subtracting from the baseline — the one thing it must never do.
    def geocode(name):
        raise ValueError("nope")

    tasks = plan_tasks(area="Scope", category="cafe", limit=10, sub_areas=["Alpha"], geocode=geocode)

    assert len(tasks) == 1
    assert tasks[0].area == "Scope"


def test_sub_areas_are_bounded_like_cells_are():
    names = [f"Area {i}" for i in range(100)]

    tasks = plan_tasks(
        area="Scope", category="cafe", limit=50, sub_areas=names, geocode=lambda name: BOX
    )

    assert len(tasks) <= MAX_CELLS
