"""Geographic partitioning — one scope becomes several bounded discovery tasks.

WHY THIS EXISTS. Every discovery provider answers a bounded question. A structured places text search
returns at most a few dozen records per query however large the area is; an Overpass sweep is bounded
by the caller's limit. So "discover dentists across a whole metro region" is not one request that
happens to be big — it is a request that silently under-answers, returning the same few businesses
however long you wait. Splitting the scope is the only way to exceed that ceiling, which makes
partitioning a correctness concern rather than an optimization.

NO GEOGRAPHY LIVES HERE, AND THAT IS THE POINT. This module knows nothing about cities, provinces,
regions or countries. It splits a BOUNDING BOX — four numbers — into a grid of smaller boxes. A
rectangle can be divided without knowing which country it covers, so there is no place list to
maintain, nothing to keep in sync with a map, and no way for a demo area to become a default. The
only alternative source of sub-areas is a set of NAMES proposed at runtime (by a model or by
configuration), and those are accepted only after a geocoder resolves each one — see
``plan_tasks``.

BOUNDED BY CONSTRUCTION, BECAUSE A FAN-OUT IS WHAT A CRAWLER IS MADE OF. A partition multiplies
provider requests, so the cell count is clamped to ``MAX_CELLS`` and partitioning is opt-in: the
default of one cell reproduces today's single-request behaviour exactly. Widening coverage is meant
to happen by scheduling more targets over more nights, not by letting one invocation fan out without
limit.
"""

from __future__ import annotations

import math
from dataclasses import dataclass
from typing import Callable, Iterable, Optional

#: A bounding box as the geocoder and every provider already express it: south, west, north, east.
BBox = tuple[float, float, float, float]

#: Hard ceiling on cells from a single scope. A partition is a request fan-out — one invocation
#: asking for 400 cells is a crawler, which is explicitly out of scope. Raising this is a product
#: decision about quota and cost, never a convenience for one run.
MAX_CELLS = 16

#: One cell unless asked otherwise: the unpartitioned path stays byte-for-byte today's behaviour.
DEFAULT_CELLS = 1


@dataclass(frozen=True)
class DiscoveryTask:
    """One bounded unit of discovery work: a category, within a box, up to a limit.

    ``area`` is kept alongside ``bbox`` because providers still use it for the free-text half of a
    search and because it is what an operator reads in a log. ``bbox``, when present, is what actually
    constrains the search — a provider given both must restrict to the box, or a partition would
    return the same scope-wide results in every cell.
    """

    area: str
    category: str
    limit: int
    #: None means "resolve the area yourself", i.e. exactly the pre-partition behaviour.
    bbox: Optional[BBox] = None
    #: Human label for logs/metrics. Never parsed, never a key, never geography.
    label: str = ""

    def described(self) -> str:  # pragma: no cover - display only
        return f"{self.category} in {self.area}" + (f" [{self.label}]" if self.label else "")


def clamp_cells(cells: Optional[int]) -> int:
    """How many cells a scope may be split into. Out-of-range values are clamped, never rejected.

    A partition count is an operational dial, not a correctness input: a typo should cost a smaller
    sweep than intended, not fail a scheduled run that would otherwise have done useful work.
    """
    if cells is None:
        return DEFAULT_CELLS
    if isinstance(cells, bool) or not isinstance(cells, int):
        return DEFAULT_CELLS
    return max(1, min(cells, MAX_CELLS))


def grid_shape(cells: int) -> tuple[int, int]:
    """Rows and columns for ``cells``, as square as possible.

    Square-ish cells matter because a provider's result cap applies per request: a 1x8 strip of very
    wide, very short boxes caps the same way a single box does along its long axis, while a 3x3 grid
    divides the area in both directions. The shape is derived, never tabulated.
    """
    cells = max(1, cells)
    cols = int(math.ceil(math.sqrt(cells)))
    rows = int(math.ceil(cells / cols))
    return rows, cols


def partition_bbox(bbox: BBox, cells: int) -> list[BBox]:
    """Split one bounding box into up to ``cells`` smaller, non-overlapping boxes.

    Pure geometry and pure function: no network, no geocoder, no place names. Cells tile the original
    box exactly — adjacent cells share an edge, so a business on a boundary is discovered by whichever
    cell contains it and dedup at ingest settles the rest. Nothing is lost between cells and nothing
    outside the original box is added.
    """
    south, west, north, east = bbox
    cells = clamp_cells(cells)
    if cells == 1:
        return [bbox]

    # A degenerate box (a point, or inverted input) cannot be meaningfully divided. Returning it
    # whole is correct and keeps the caller on the ordinary single-task path.
    if north <= south or east <= west:
        return [bbox]

    rows, cols = grid_shape(cells)
    lat_step = (north - south) / rows
    lng_step = (east - west) / cols

    boxes: list[BBox] = []
    for row in range(rows):
        for col in range(cols):
            if len(boxes) >= cells:
                return boxes
            boxes.append((
                south + lat_step * row,
                west + lng_step * col,
                south + lat_step * (row + 1),
                west + lng_step * (col + 1),
            ))
    return boxes


def plan_tasks(
    *,
    area: str,
    category: str,
    limit: int,
    cells: Optional[int] = None,
    bbox: Optional[BBox] = None,
    sub_areas: Optional[Iterable[str]] = None,
    geocode: Optional[Callable[[str], BBox]] = None,
) -> list[DiscoveryTask]:
    """Turn one scope into the bounded tasks that will actually be executed.

    Three shapes, in descending order of preference:

    1. **Named sub-areas** (``sub_areas``), when a ``geocode`` callback can resolve them. Named areas
       beat a geometric grid because they follow real administrative boundaries, which is what a
       provider's own data is organised around. Each name MUST resolve: a name the geocoder refuses is
       dropped silently, so a proposed area that does not exist can never become a search. This is the
       only path by which a model's geographic suggestion reaches execution, and it cannot bypass the
       geocoder.
    2. **A geometric grid**, when ``cells`` > 1 and a box is available. Knows no geography at all.
    3. **A single task**, the default and today's exact behaviour.

    ``limit`` is DIVIDED across tasks rather than repeated, so partitioning changes where a run looks,
    never how much it may ingest. Without that, ``--partition`` would quietly multiply a target's
    configured volume, and the caps that protect shared infrastructure would stop meaning anything.
    """
    cells = clamp_cells(cells)

    resolved: list[DiscoveryTask] = []

    names = [n.strip() for n in (sub_areas or []) if isinstance(n, str) and n.strip()]
    if names and geocode is not None:
        for name in names[:MAX_CELLS]:
            try:
                resolved.append(DiscoveryTask(
                    area=name,
                    category=category,
                    limit=limit,
                    bbox=geocode(name),
                    label="sub-area",
                ))
            except Exception:  # noqa: BLE001 - an unresolvable proposal is dropped, never guessed at.
                continue
        if resolved:
            return _share_limit(resolved, limit)

    if cells > 1 and bbox is not None:
        boxes = partition_bbox(bbox, cells)
        if len(boxes) > 1:
            return _share_limit([
                DiscoveryTask(
                    area=area,
                    category=category,
                    limit=limit,
                    bbox=box,
                    label=f"cell {index + 1}/{len(boxes)}",
                )
                for index, box in enumerate(boxes)
            ], limit)

    # The unpartitioned path. `bbox` is passed through when the caller already resolved one, so a
    # single-cell partition run does not geocode the same area twice.
    return [DiscoveryTask(area=area, category=category, limit=limit, bbox=bbox)]


def _share_limit(tasks: list[DiscoveryTask], limit: int) -> list[DiscoveryTask]:
    """Divide the caller's limit across tasks, never multiplying it.

    Every task gets at least one, because a task allowed to discover nothing is a request spent for
    certain no result. The remainder goes to the earliest tasks rather than being dropped, so the
    totals still add up to the caller's limit whenever the arithmetic allows it.
    """
    count = len(tasks)
    if count <= 1:
        return tasks

    base, remainder = divmod(max(limit, count), count)
    return [
        DiscoveryTask(
            area=task.area,
            category=task.category,
            limit=base + (1 if index < remainder else 0),
            bbox=task.bbox,
            label=task.label,
        )
        for index, task in enumerate(tasks)
    ]


def format_bbox(bbox: BBox) -> str:
    """One box as the CLI accepts it back: ``south,west,north,east``.

    Round-trips with :func:`parse_bbox`, so a box printed in a log can be pasted into a command and
    reproduce the same search exactly.
    """
    return ",".join(f"{value:.6f}" for value in bbox)


def parse_bbox(value: Optional[str]) -> Optional[BBox]:
    """Parse ``--bbox "south,west,north,east"``. ``None`` and blank mean "no box", not an error.

    STRICT, AND LOUD WHEN WRONG — unlike :func:`clamp_cells`, which quietly clamps a nonsense cell
    count. The difference is what the two inputs mean. A bad cell count costs a differently sized
    sweep; a bad box costs a search of the WRONG PLACE, and a silently corrected one would be the
    worst outcome of all: coordinates swapped into another country would produce real businesses that
    nothing downstream could tell apart from the ones that were asked for. So a malformed box raises,
    the run exits non-zero, and nothing is spent guessing what the caller meant.

    The order is ``south,west,north,east`` — latitude first — because that is the order the geocoder,
    every provider adapter and :class:`DiscoveryTask` already use. It is validated rather than
    assumed: a caller who supplies the lon/lat order of GeoJSON gets an error here instead of a
    search somewhere in the ocean.
    """
    if value is None:
        return None

    text = value.strip()
    if text == "":
        return None

    parts = [part.strip() for part in text.split(",")]
    if len(parts) != 4:
        raise ValueError(
            f"--bbox needs exactly four comma-separated numbers as south,west,north,east (got {len(parts)})."
        )

    try:
        south, west, north, east = (float(part) for part in parts)
    except ValueError as exc:  # noqa: TRY003 - the message IS the diagnostic here.
        raise ValueError(f"--bbox must be four numbers as south,west,north,east: {text!r}") from exc

    for name, latitude in (("south", south), ("north", north)):
        if not -90.0 <= latitude <= 90.0:
            raise ValueError(f"--bbox {name} must be a latitude between -90 and 90 (got {latitude}).")
    for name, longitude in (("west", west), ("east", east)):
        if not -180.0 <= longitude <= 180.0:
            raise ValueError(f"--bbox {name} must be a longitude between -180 and 180 (got {longitude}).")

    # A zero-area or inverted box is almost always lat/lon transposed. Refuse it rather than
    # searching whatever degenerate strip it describes.
    if north <= south or east <= west:
        raise ValueError(
            f"--bbox must be south,west,north,east with south < north and west < east (got {text!r})."
        )

    return (south, west, north, east)
