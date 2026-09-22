"""``--bbox``: a search that covers the box it was PLANNED to cover.

WHY THIS EXISTS. A nationwide run computes its cells from the national polygon, clips each one to the
country, and then has to make the provider search exactly that rectangle. Without a way to hand the
box over, the engine would re-derive the area from its NAME through a geocoder — which answers with
the bounding box of a place, a different shape from the cell that was planned. The search would drift
off its cell, cover ground twice, miss ground entirely, and the national clipping would mean nothing.

Pinned here:

  - **A malformed box raises rather than being corrected.** Unlike the cell count, which is clamped, a
    wrong box is a search of the WRONG PLACE. Silently fixing one could put a run in another country
    and produce real businesses nothing downstream could tell apart from the ones asked for.
  - **A supplied box replaces the geocoder outright** — no lookup, and the area becomes a label.
  - **The box round-trips**, so a box in a log can be pasted back into a command.
  - **The AI planner is not consulted for a Google-only run**, checked locally rather than trusted to
    a setting in another process.
  - **Nothing changes when no box is given**: the unbounded path is byte-for-byte what it was.

No test touches the network.
"""

import pytest

from scraper.config import Config
from scraper.partition import format_bbox, parse_bbox

# A cell somewhere in the Visayas. Its coordinates matter only in that they are a valid box.
CELL = (10.25, 123.75, 10.50, 124.00)


def config(**overrides) -> Config:
    base = dict(
        discovery_provider="google_places",
        crm_base_url="http://localhost:8001/api/v1/crm",
        crm_token="test-token",
        overpass_url="https://overpass-api.de/api/interpreter",
        nominatim_url="https://nominatim.openstreetmap.org",
        user_agent="test",
        request_timeout=60.0,
        openai_timeout=30.0,
    )
    return Config(**{**base, **overrides})


# --- parsing: strict, and loud when wrong ---------------------------------------------------------


def test_a_well_formed_box_parses_in_south_west_north_east_order():
    assert parse_bbox("10.25,123.75,10.5,124.0") == CELL


def test_absence_is_not_an_error():
    assert parse_bbox(None) is None
    assert parse_bbox("") is None
    assert parse_bbox("   ") is None


@pytest.mark.parametrize(
    "value",
    [
        "10.25,123.75,10.5",                 # three numbers
        "10.25,123.75,10.5,124.0,11.0",      # five
        "10.25,123.75,north,124.0",          # not a number
        "",                                  # handled above, kept for symmetry of intent
    ],
)
def test_a_malformed_box_is_refused_or_absent(value):
    if value == "":
        assert parse_bbox(value) is None
        return
    with pytest.raises(ValueError):
        parse_bbox(value)


def test_a_transposed_box_is_refused_rather_than_searched():
    # GeoJSON order (lon, lat) supplied by mistake. Left uncorrected this searches the ocean.
    # Caught by the latitude range here rather than by the ordering rule, which is the earlier and
    # more specific of the two fences — what matters is that it is refused, not which fence caught it.
    with pytest.raises(ValueError, match="latitude"):
        parse_bbox("123.75,10.25,124.0,10.5")


def test_a_transposed_box_inside_the_valid_ranges_is_still_refused():
    # Both numbers are plausible latitudes AND longitudes, so only the ordering rule can catch this.
    with pytest.raises(ValueError, match="south < north"):
        parse_bbox("14.0,10.0,12.0,11.0")


def test_a_degenerate_box_is_refused():
    with pytest.raises(ValueError, match="south < north"):
        parse_bbox("10.5,123.75,10.5,124.0")


@pytest.mark.parametrize(
    "value,message",
    [
        ("-91,120,10,124", "latitude"),
        ("10,120,91,124", "latitude"),
        ("10,-181,12,124", "longitude"),
        ("10,120,12,181", "longitude"),
    ],
)
def test_coordinates_outside_the_world_are_refused(value, message):
    with pytest.raises(ValueError, match=message):
        parse_bbox(value)


def test_a_box_round_trips_through_its_own_format():
    assert parse_bbox(format_bbox(CELL)) == CELL


# --- planning: a supplied box replaces the geocoder ------------------------------------------------


def _plan(monkeypatch, *, bbox, partition=None, geocoded=(0.0, 0.0, 1.0, 1.0)):
    """Plan tasks while recording whether the geocoder was opened at all."""
    from scraper import cli

    opened = []

    class StubGeocoder:
        def geocode_area(self, area):
            opened.append(area)
            return geocoded

        def close(self):
            pass

    monkeypatch.setattr(cli, "build_openstreetmap", lambda cfg: StubGeocoder())
    tasks = cli._plan_discovery_tasks(
        config(), area="Cebu", category="bakery", limit=20, partition=partition, bbox=bbox
    )
    return tasks, opened


def test_a_supplied_box_is_searched_and_nothing_is_geocoded(monkeypatch):
    tasks, geocoded = _plan(monkeypatch, bbox=CELL)

    assert len(tasks) == 1
    assert tasks[0].bbox == CELL
    # The area survives as a LABEL only; resolving it would have produced a different shape.
    assert tasks[0].area == "Cebu"
    assert geocoded == [], "a pre-resolved box must not be re-derived from a place name"


def test_a_supplied_box_is_the_one_that_gets_partitioned(monkeypatch):
    tasks, geocoded = _plan(monkeypatch, bbox=CELL, partition=4)

    assert len(tasks) == 4
    assert geocoded == []
    south, west, north, east = CELL
    for task in tasks:
        s, w, n, e = task.bbox
        assert south <= s < n <= north
        assert west <= w < e <= east


def test_without_a_box_the_unbounded_path_is_unchanged(monkeypatch):
    tasks, geocoded = _plan(monkeypatch, bbox=None)

    assert len(tasks) == 1
    assert tasks[0].bbox is None
    assert geocoded == [], "an unpartitioned run still costs no geocode"


# --- the AI planner is not consulted for a Google-only run ----------------------------------------


def test_the_ai_planner_is_not_asked_when_ai_is_disabled(monkeypatch):
    from scraper import cli

    asked = []
    monkeypatch.setattr(
        "scraper.strategy.fetch_strategy",
        lambda **kwargs: asked.append(kwargs),
    )

    # Default configuration: AI off. This is the Google-only phase.
    assert cli._planned_sub_areas(config(), area="Cebu", category="bakery") is None
    assert asked == [], "a Google-only run must reach no model, and must not even ask"


def test_a_partitioned_google_run_consults_no_planner(monkeypatch):
    """The regression this gate exists for.

    The partitioner used to call the strategy endpoint unconditionally, so switching a Google run to
    ``--partition`` opened the AI channel even though the provider itself had AI explicitly disabled.
    Nothing was charged only because the CRM refused at the other end — a fence in another process.
    """
    from scraper import cli

    asked = []
    monkeypatch.setattr("scraper.strategy.fetch_strategy", lambda **kwargs: asked.append(kwargs))
    _plan(monkeypatch, bbox=None, partition=4)

    assert asked == []


def test_the_planner_is_still_available_when_ai_is_switched_on(monkeypatch):
    """Off by default is not the same as removed: the capability stays reachable for the AI phase."""
    from scraper import cli
    from scraper.strategy import DiscoveryStrategy

    monkeypatch.setattr(
        "scraper.strategy.fetch_strategy",
        lambda **kwargs: DiscoveryStrategy(sub_areas=["Mandaue City"]),
    )

    assert cli._planned_sub_areas(
        config(use_ai_strategy=True), area="Cebu", category="bakery"
    ) == ["Mandaue City"]


# --- the whole CLI path: the flag reaches the provider, unchanged -----------------------------------


class RecordingProvider:
    """Captures exactly how ``discover`` was called, including whether a box was passed at all."""

    name = "recordingprovider"

    def __init__(self):
        self.calls = []

    def discover(self, *, area, category, limit, **kwargs):
        self.calls.append({"area": area, "category": category, "limit": limit, **kwargs})
        return []

    def close(self):
        pass


def _run_cli(monkeypatch, argv):
    """Drive the real ``main()`` with a recording provider and no CRM. Returns (exit code, provider)."""
    from scraper import cli

    provider = RecordingProvider()
    monkeypatch.setattr(cli, "get_provider", lambda config: provider)
    monkeypatch.setattr(cli.run_metrics, "append", lambda record: None)

    return cli.main(argv), provider


def test_the_bbox_flag_reaches_the_provider_verbatim(monkeypatch):
    """The link that makes nationwide work at all.

    A cell is computed and clipped in the CRM, handed to this process on the command line, and has to
    arrive at the provider as the same four numbers. Anything that re-derives, rounds or drops it here
    would silently search a different rectangle from the one the national boundary approved.
    """
    code, provider = _run_cli(
        monkeypatch,
        ["discover", "--area", "Cebu", "--category", "bakery", "--limit", "20",
         "--bbox", "10.25,123.75,10.5,124.0", "--print-only"],
    )

    assert code == 0
    assert len(provider.calls) == 1
    assert provider.calls[0]["bbox"] == CELL


def test_without_the_flag_the_provider_is_called_exactly_as_before(monkeypatch):
    """`bbox` travels only when there is one — an optional argument that is always sent is not optional.

    A provider written before the bounded contract existed (a third-party implementation, or a test
    double with no ``bbox`` parameter) must still be callable.
    """
    code, provider = _run_cli(
        monkeypatch,
        ["discover", "--area", "Cebu", "--category", "bakery", "--limit", "20", "--print-only"],
    )

    assert code == 0
    assert "bbox" not in provider.calls[0]


def test_a_malformed_bbox_exits_non_zero_without_calling_the_provider(monkeypatch):
    """A bad box must cost a refusal, never a search of whatever it happened to describe."""
    code, provider = _run_cli(
        monkeypatch,
        ["discover", "--area", "Cebu", "--category", "bakery",
         "--bbox", "123.75,10.25,124.0,10.5", "--print-only"],
    )

    assert code == 2
    assert provider.calls == []


# --- the country fence: a foreign location cannot become a search ---------------------------------


class RecordingGeocodeClient:
    """Stands in for httpx, recording the Nominatim query and answering with canned results."""

    def __init__(self, results):
        self.results = results
        self.calls = []

    def get(self, url, params=None, **kwargs):
        self.calls.append({"url": url, "params": params or {}})
        return self

    def raise_for_status(self):
        return None

    def json(self):
        return self.results


def _osm(results, country_code=None):
    from scraper.providers.openstreetmap import OpenStreetMapProvider

    provider = OpenStreetMapProvider(
        overpass_url="https://overpass.invalid",
        nominatim_url="https://nominatim.invalid",
        user_agent="test",
        country_code=country_code,
    )
    provider._client = RecordingGeocodeClient(results)
    return provider


PH_RESULT = [{"boundingbox": ["10.2", "10.4", "123.8", "124.0"]}]


def test_the_geocoder_confines_itself_to_the_configured_country():
    provider = _osm(PH_RESULT, country_code="PH")
    provider._geocode_area("Cebu City")

    # Lowercased: Nominatim expects ISO 3166-1 alpha-2 in lower case.
    assert provider._client.calls[0]["params"]["countrycodes"] == "ph"


def test_without_a_country_the_geocoder_is_unrestricted_exactly_as_before():
    provider = _osm(PH_RESULT)
    provider._geocode_area("Cebu City")

    assert "countrycodes" not in provider._client.calls[0]["params"]


def test_a_place_that_does_not_exist_in_the_country_raises_rather_than_resolving_elsewhere():
    """THE FENCE. Nominatim returns nothing for a foreign place once the country is set, and this
    raises — so the caller reports an unresolved area and performs NO search."""
    provider = _osm([], country_code="PH")

    with pytest.raises(ValueError, match="PH"):
        provider._geocode_area("Kota Kinabalu")


def test_an_unresolvable_area_costs_the_google_provider_zero_requests(monkeypatch):
    """The regression that matters commercially.

    Google is only ever asked about a BOX, and the box comes from the geocoder. A location the
    country fence refuses therefore cannot reach Google at all — the provider reports
    `area_unresolved` having sent nothing. This is what makes "an outside-Philippines location cannot
    trigger Google discovery" true before any money is spent, rather than after results are thrown
    away at ingest.
    """
    from scraper.providers.google_places import GooglePlacesProvider

    class RefusingGeocoder:
        def geocode_area(self, area):
            raise ValueError(f"Nominatim could not resolve area '{area}' in country 'PH'.")

        def close(self):
            pass

    sent = []

    provider = GooglePlacesProvider(
        crm_base_url="http://crm.invalid/api/v1/crm",
        crm_token="test-token",
        timeout=5.0,
        osm=RefusingGeocoder(),
    )
    monkeypatch.setattr(
        provider, "_client", type("C", (), {"post": lambda self, *a, **k: sent.append(a)})()
    )

    found = provider.discover(area="Kota Kinabalu", category="bakery", limit=20)

    assert found == []
    assert sent == [], "no request may be made for an area the country fence refused"
    assert provider.stats()["execution_status"] == "area_unresolved"


def test_the_country_reaches_the_geocoder_through_the_cli(monkeypatch):
    """`--country` is a property of the RUN, not of the engine's environment."""
    from scraper import cli
    from scraper.providers import build_openstreetmap

    built = {}

    def spy(config):
        built["country"] = config.discovery_country
        return build_openstreetmap(config)

    monkeypatch.setattr(cli, "build_openstreetmap", spy)
    monkeypatch.setattr(cli.run_metrics, "append", lambda record: None)

    provider = RecordingProvider()
    monkeypatch.setattr(cli, "get_provider", lambda config: provider)

    cli.main([
        "discover", "--area", "Cebu", "--category", "bakery", "--limit", "20",
        "--country", "PH", "--partition", "4", "--print-only",
    ])

    assert built["country"] == "PH"
