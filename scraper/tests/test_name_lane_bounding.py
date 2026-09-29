"""The AI name lane's OSM lookup is bounded by the search cell when there is one (gap 2a).

Without a cell the lookup is exactly what it always was: the whole geocoded area, `[timeout:60]`,
and the response taken as-is. With a cell it searches the cell widened by a fixed margin and clipped
to the area — never the province-sized box of a nationwide cell's anchor label, which outlasted the
query's own timeout — and a server-side timeout raises instead of reading as "no matches".

No network: Nominatim is stubbed and Overpass is an httpx MockTransport.
"""

import httpx
import pytest

from scraper.models import RawBusiness
from scraper.providers.ai_assisted import AiAssistedDiscoveryProvider
from scraper.providers.openai_discovery import OpenAiDiscoveryProvider
from scraper.providers.openstreetmap import (
    NAME_LOOKUP_MARGIN_DEGREES,
    NAME_LOOKUP_SERVER_TIMEOUT_SECONDS,
    OpenStreetMapProvider,
    SelectorSweep,
    name_lookup_box,
)

#: "Cebu" as Nominatim answers it: the province, about 2.3° x 1.4°.
PROVINCE = (9.4, 123.2, 11.3, 124.6)
#: One 0.25° nationwide cell inside it.
CELL = (10.25, 123.75, 10.5, 124.0)


def named(i: int, name: str = "Gan Dental") -> dict:
    return {"type": "node", "id": i, "lat": 10.3, "lon": 123.9, "tags": {"name": name, "amenity": "dentist"}}


def osm_with(handler) -> tuple[OpenStreetMapProvider, list[str]]:
    """A real provider whose area geocode answers PROVINCE and whose Overpass is `handler`."""
    p = OpenStreetMapProvider(
        overpass_url="https://overpass.test/api/interpreter",
        nominatim_url="https://n.test",
        user_agent="ua",
        reverse_geocode=False,
    )
    geocoded: list[str] = []

    def geocode(area: str):
        geocoded.append(area)
        return PROVINCE

    p._geocode_area = geocode  # type: ignore[method-assign]
    # Keep the provider's own client timeout (REQUEST_TIMEOUT, 60 by default): that is what the
    # server timeout is measured against.
    p._client = httpx.Client(transport=httpx.MockTransport(handler), timeout=p.timeout)
    return p, geocoded


def recording(response_json: dict):
    sent: dict = {}

    def handler(request: httpx.Request) -> httpx.Response:
        sent["body"] = request.content.decode()
        sent["timeout"] = request.extensions.get("timeout")
        return httpx.Response(200, json=response_json)

    return handler, sent


def box_in(query: str) -> str:
    return query.split("](", 1)[1].split(")", 1)[0]


# --- the box ------------------------------------------------------------------------------------


def test_the_box_is_the_cell_widened_by_the_margin_when_the_area_contains_it():
    m = NAME_LOOKUP_MARGIN_DEGREES
    assert m == 0.1
    assert name_lookup_box(CELL, PROVINCE) == pytest.approx(
        (CELL[0] - m, CELL[1] - m, CELL[2] + m, CELL[3] + m)
    )


def test_the_box_is_clipped_to_the_area():
    area = (10.3, 123.8, 10.45, 124.05)  # a small city box inside the widened cell
    assert name_lookup_box(CELL, area) == pytest.approx((10.3, 123.8, 10.45, 124.05))


def test_the_box_never_exceeds_the_widened_cell_even_when_the_area_does_not_reach_the_cell():
    # A nationwide cell labelled with an anchor city whose own box is elsewhere: the planned cell is
    # the ground, so it is searched (widened) rather than nothing, and never the anchor's box.
    elsewhere = (14.4, 120.9, 14.8, 121.1)
    m = NAME_LOOKUP_MARGIN_DEGREES
    assert name_lookup_box(CELL, elsewhere) == pytest.approx(
        (CELL[0] - m, CELL[1] - m, CELL[2] + m, CELL[3] + m)
    )


# --- find_by_name without a cell: unchanged -------------------------------------------------------


def test_without_a_cell_the_whole_geocoded_area_is_searched_exactly_as_before():
    handler, sent = recording({"elements": [named(1)]})
    p, geocoded = osm_with(handler)

    found = p.find_by_name(["Gan Dental"], area="Cebu")

    assert geocoded == ["Cebu"]
    assert box_in(sent["body"]) == "9.4,123.2,11.3,124.6"
    assert "[timeout:60]" in sent["body"]
    assert sent["timeout"]["read"] == 60.0
    assert [b.provider_id for b in found] == ["node/1"]


def test_without_a_cell_a_server_remark_is_still_not_inspected():
    # Unchanged handling: the unbounded path never looked at `remark`, and still does not.
    handler, _ = recording({"remark": "runtime error: Query timed out", "elements": [named(1)]})
    p, _ = osm_with(handler)

    assert [b.provider_id for b in p.find_by_name(["Gan Dental"], area="Cebu")] == ["node/1"]


# --- find_by_name with a cell: bounded -------------------------------------------------------------


def test_with_a_cell_only_the_widened_cell_is_searched_not_the_province():
    handler, sent = recording({"elements": [named(1)]})
    p, geocoded = osm_with(handler)

    found = p.find_by_name(["Gan Dental"], area="Cebu", bbox=CELL)

    assert geocoded == ["Cebu"]  # still geocoded: it is what the box is clipped to
    s, w, n, e = (float(x) for x in box_in(sent["body"]).split(","))
    assert (s, w, n, e) == pytest.approx((10.15, 123.65, 10.6, 124.1))
    assert box_in(sent["body"]) != "9.4,123.2,11.3,124.6"
    assert [b.provider_id for b in found] == ["node/1"]


def test_with_a_cell_the_server_timeout_is_below_the_client_timeout():
    handler, sent = recording({"elements": []})
    p, _ = osm_with(handler)

    p.find_by_name(["Gan Dental"], area="Cebu", bbox=CELL)

    assert f"[timeout:{NAME_LOOKUP_SERVER_TIMEOUT_SECONDS}]" in sent["body"]
    assert "[timeout:60]" not in sent["body"]
    assert NAME_LOOKUP_SERVER_TIMEOUT_SECONDS < sent["timeout"]["read"]


def test_with_a_cell_a_server_side_timeout_raises_instead_of_reading_as_no_matches():
    handler, _ = recording({
        "remark": 'runtime error: Query timed out in "query" at line 1 after 25 seconds.',
        "elements": [],
    })
    p, _ = osm_with(handler)

    with pytest.raises(RuntimeError, match="Overpass name lookup failed on the server"):
        p.find_by_name(["Gan Dental"], area="Cebu", bbox=CELL)


def test_with_a_cell_a_harmless_remark_is_not_an_error():
    handler, _ = recording({"remark": "note: something informational", "elements": [named(1)]})
    p, _ = osm_with(handler)

    assert [b.provider_id for b in p.find_by_name(["Gan Dental"], area="Cebu", bbox=CELL)] == ["node/1"]


def test_the_rest_of_the_query_is_unchanged_by_the_cell():
    h1, unbounded = recording({"elements": []})
    p1, _ = osm_with(h1)
    p1.find_by_name(["Gan Dental"], area="Cebu")

    h2, bounded = recording({"elements": []})
    p2, _ = osm_with(h2)
    p2.find_by_name(["Gan Dental"], area="Cebu", bbox=CELL)

    def normalized(q: str, box: str, timeout: int) -> str:
        return q.replace(box, "BOX").replace(f"[timeout:{timeout}]", "[timeout:T]")

    assert normalized(unbounded["body"], box_in(unbounded["body"]), 60) == normalized(
        bounded["body"], box_in(bounded["body"]), NAME_LOOKUP_SERVER_TIMEOUT_SECONDS
    )


# --- the name-lane provider -------------------------------------------------------------------------


class StrictOsm:
    """A lookup written before the parameter existed: it accepts no `bbox` at all."""

    def __init__(self) -> None:
        self.calls: list[dict] = []

    def find_by_name(self, names, *, area, limit=200):
        self.calls.append({"names": list(names), "area": area})
        return [RawBusiness(provider_id="node/1", name="Gan Dental", latitude=10.3, longitude=123.9)]

    def close(self):
        pass


class BoundedOsm(StrictOsm):
    def find_by_name(self, names, *, area, limit=200, bbox=None):
        self.calls.append({"names": list(names), "area": area, "bbox": bbox})
        return [RawBusiness(provider_id="node/1", name="Gan Dental", latitude=10.3, longitude=123.9)]


def crm_ok(names: list) -> httpx.Response:
    return httpx.Response(
        200,
        json={"data": {"effectiveProvider": "openai", "executionStatus": "ok", "errorReason": None,
                       "proposals": names, "usage": {}}},
        request=httpx.Request("POST", "http://crm.test"),
    )


def name_lane(monkeypatch, osm) -> tuple[OpenAiDiscoveryProvider, list[dict]]:
    provider = OpenAiDiscoveryProvider(
        crm_base_url="http://crm.test/api/v1/crm", crm_token="t", timeout=5, osm=osm
    )
    asked: list[dict] = []

    def post(url, **kw):
        asked.append(kw.get("json") or {})
        return crm_ok(["Gan Dental"])

    monkeypatch.setattr(provider._client, "post", post)
    return provider, asked


def test_the_name_lane_without_a_cell_calls_a_pre_parameter_lookup_exactly_as_before(monkeypatch):
    osm = StrictOsm()
    provider, _ = name_lane(monkeypatch, osm)

    found = provider.discover(area="Cebu", category="dentist", limit=10)

    assert osm.calls == [{"names": ["Gan Dental"], "area": "Cebu"}]
    assert [b.provider_id for b in found] == ["node/1"]


def test_the_name_lane_passes_its_cell_to_the_lookup(monkeypatch):
    osm = BoundedOsm()
    provider, asked = name_lane(monkeypatch, osm)

    provider.discover(area="Cebu", category="dentist", limit=10, bbox=CELL)

    assert osm.calls == [{"names": ["Gan Dental"], "area": "Cebu", "bbox": CELL}]
    # The proposals are still asked about the AREA; only the lookup is bounded.
    assert asked and asked[0].get("area") == "Cebu"


def test_a_cell_does_not_stick_to_the_next_unbounded_search(monkeypatch):
    osm = BoundedOsm()
    provider, _ = name_lane(monkeypatch, osm)

    provider.discover(area="Cebu", category="dentist", limit=10, bbox=CELL)
    provider.discover(area="Cebu", category="dentist", limit=10)

    assert [c["bbox"] for c in osm.calls] == [CELL, None]


# --- through the composite ----------------------------------------------------------------------------


class SweepOsm:
    def __init__(self) -> None:
        self.bboxes: list = []

    def discover(self, *, area, category, limit, bbox=None):
        self.bboxes.append(bbox)
        return []

    def find_by_selectors(self, selectors, *, area, limit=200):
        return SelectorSweep()

    def close(self):
        pass


def test_the_composite_bounds_the_name_lookup_by_the_cell_it_was_given(monkeypatch):
    lane = BoundedOsm()
    ai, _ = name_lane(monkeypatch, lane)
    sweep = SweepOsm()
    composite = AiAssistedDiscoveryProvider(osm=sweep, ai=ai)

    found = composite.discover(area="Cebu", category="dentist", limit=20, bbox=CELL)

    assert sweep.bboxes == [CELL]
    assert lane.calls and lane.calls[0]["bbox"] == CELL
    assert [b.provider_id for b in found] == ["node/1"]
