"""OpenStreetMap discovery provider (v1).

Discovers businesses with the Overpass API and resolves administrative location with Nominatim.
Uses only open data — no API key, no billing. This is simply the *first* ``DiscoveryProvider``; a
Google Places provider can be added later with no change outside this package.

OSM etiquette honored: a descriptive User-Agent, and Nominatim reverse-geocoding throttled to <=1
request/second per the usage policy.
"""

from __future__ import annotations

import atexit
import re
import time
from dataclasses import dataclass, field
from typing import Optional

import httpx

from scraper.models import RawBusiness
from scraper.nominatim_gate import geocode_cache_key, open_gate
from scraper.providers.base import DiscoveryProvider

# Friendly category -> OSM tag selectors (key, value). Extend freely; unknown words fall through to
# a raw "key=value" selector so callers can target any OSM tag directly.
CATEGORY_SELECTORS: dict[str, list[tuple[str, str]]] = {
    "cafe": [("amenity", "cafe")],
    "restaurant": [("amenity", "restaurant")],
    "fast_food": [("amenity", "fast_food")],
    "bakery": [("shop", "bakery")],
    "bar": [("amenity", "bar")],
    "hotel": [("tourism", "hotel")],
    "dentist": [("amenity", "dentist")],
    "clinic": [("amenity", "clinic")],
    "doctor": [("amenity", "doctors")],
    "pharmacy": [("amenity", "pharmacy")],
    "salon": [("shop", "hairdresser"), ("shop", "beauty")],
    "spa": [("leisure", "spa"), ("shop", "massage")],
    "gym": [("leisure", "fitness_centre")],
    "car_repair": [("shop", "car_repair")],
    "hardware": [("shop", "hardware")],
    "supermarket": [("shop", "supermarket")],
    "real_estate": [("office", "estate_agent")],
    "travel_agency": [("shop", "travel_agency")],
    "school": [("amenity", "school")],
}

# OSM tag keys that describe what a business *is*, most specific first — used to derive `industry`.
_CATEGORY_KEYS = ["shop", "amenity", "tourism", "office", "leisure", "craft", "healthcare"]

#: OSM values that name INFRASTRUCTURE, not a business — refused by ``find_by_name`` only.
#:
#: WHY THIS EXISTS AND WHY ONLY THERE. ``discover()`` names the tags it wants, so it can never return
#: one of these. ``find_by_name`` deliberately drops the category filter — that is the whole point of
#: it — and matches on name prefix, which means a real business's name can be carried by a nearby map
#: feature: a live measurement returned "Grandpa's Inn and Restaurant Customer Parking" as a
#: candidate lead. A car park is not a prospect, and letting one become a lead with a real
#: ``place_id`` would be a permanent wrong row.
#:
#: A FLOOR, NOT A TAXONOMY. This refuses the obvious non-businesses that share a business's name; it
#: does not try to decide what counts as a good lead. A gift shop found while looking for restaurants
#: is still a real business and still passes — judging that is the CRM's job, not this module's.
_NON_BUSINESS_VALUES: dict[str, set[str]] = {
    "amenity": {
        "parking", "parking_space", "parking_entrance", "motorcycle_parking", "bicycle_parking",
        "bench", "shelter", "toilets", "waste_basket", "waste_disposal", "drinking_water",
        "fountain", "recycling", "post_box", "telephone", "clock", "grit_bin", "bbq",
    },
    "leisure": {"park", "playground", "pitch", "garden", "picnic_table", "slipway"},
    "tourism": {"artwork", "picnic_site", "viewpoint", "information"},
}


def is_business_element(element: dict) -> bool:
    """Whether a named OSM element plausibly represents a BUSINESS rather than a map feature.

    Used by name-based lookup, where the category filter is deliberately absent. Returns True for
    anything not positively identified as infrastructure — refusing only what is clearly not a
    business, rather than trying to prove that something is one.
    """
    tags = element.get("tags") or {}
    for key, excluded in _NON_BUSINESS_VALUES.items():
        if str(tags.get(key) or "").strip().lower() in excluded:
            return False
    return True


#: Nominatim usage policy: an absolute maximum of one request per second. Spacing is measured
#: START-TO-START — the only thing the policy limits is how often requests begin — so a request that
#: takes 0.5s is followed by a 0.6s wait, not a fresh 1.1s one. The 0.1s margin is kept from the
#: sleep this replaces.
NOMINATIM_INTERVAL_SECONDS = 1.1

#: When this process last STARTED a Nominatim request (monotonic). Process-wide, not per provider:
#: one engine process builds several OSM providers (the geocoder, the baseline, the AI composite's
#: resolver), and per-instance spacing would let two of them fire inside the same second.
_nominatim_last_start: float | None = None


def _pace_nominatim() -> None:
    """Wait until a new Nominatim request may start, then record that it has."""
    global _nominatim_last_start
    now = time.monotonic()
    if _nominatim_last_start is not None:
        wait = _nominatim_last_start + NOMINATIM_INTERVAL_SECONDS - now
        if wait > 0:
            time.sleep(wait)
            now = time.monotonic()
    _nominatim_last_start = now


def _drain_nominatim_interval() -> None:
    """At exit, finish the interval of this process's last request.

    The next discovery task is a NEW process with its own spacing state, so without this a task that
    ended right after a request could let its successor start one inside the same second. The old
    trailing sleep gave the same guarantee by always ending on a pause; this keeps the guarantee and
    pays only what is left of the interval.
    """
    if _nominatim_last_start is None:
        return
    wait = _nominatim_last_start + NOMINATIM_INTERVAL_SECONDS - time.monotonic()
    if wait > 0:
        time.sleep(wait)


atexit.register(_drain_nominatim_interval)

#: The national density probe returns every named match in the country in one response (measured:
#: 17,671 restaurants, 1.83 MB, 49–68s on the public endpoint), so it needs longer than the default
#: client timeout. Matches the query's own server-side `[timeout:180]` with room for transfer.
NATIONAL_PROBE_TIMEOUT_SECONDS = 200.0

#: Volume mode's cell sweep: the query's own server-side `[timeout:60]` plus room for the response to
#: arrive. A client that gives up at the same moment the server does races it and reports a
#: ReadTimeout for a query Overpass was about to answer — measured on the AI lanes' name lookup.
CELL_SWEEP_TIMEOUT_SECONDS = 75.0

#: How far past its cell a BOUNDED name lookup reaches, in degrees (~11 km). A business the model
#: named may sit just outside the cell that prompted it; the lookup still must not sweep a province.
NAME_LOOKUP_MARGIN_DEGREES = 0.1

#: The bounded name lookup's server-side `[timeout:…]`, deliberately BELOW the client's own timeout,
#: so a slow query ends as Overpass's own reported timeout rather than a client socket ReadTimeout.
NAME_LOOKUP_SERVER_TIMEOUT_SECONDS = 25


def name_lookup_box(
    cell: tuple[float, float, float, float], area: tuple[float, float, float, float]
) -> tuple[float, float, float, float]:
    """The box a bounded name lookup searches: ``cell`` widened by the margin, clipped to ``area``.

    Clipped because the proposals were asked for BY AREA, so the lookup stays inside it. When the two
    do not overlap at all — a nationwide cell labelled with an anchor city whose own box does not
    reach it — the widened cell alone is searched: the cell is the planned ground, and the area label
    is only a name for it. Either way the result is never larger than the widened cell.
    """
    south, west, north, east = cell
    m = NAME_LOOKUP_MARGIN_DEGREES
    widened = (south - m, west - m, north + m, east + m)
    a_south, a_west, a_north, a_east = area
    clipped = (
        max(widened[0], a_south),
        max(widened[1], a_west),
        min(widened[2], a_north),
        min(widened[3], a_east),
    )
    if clipped[0] >= clipped[2] or clipped[1] >= clipped[3]:
        return widened
    return clipped

_COUNTRY_CODE = re.compile(r"^[A-Za-z]{2}$")


def category_selectors(category: str) -> list[tuple[str, str]]:
    """Resolve a friendly category word to OSM tag selectors, or parse a raw ``key=value``."""
    key = category.strip().lower()
    if key in CATEGORY_SELECTORS:
        return CATEGORY_SELECTORS[key]
    if "=" in category:
        k, v = category.split("=", 1)
        return [(k.strip(), v.strip())]
    raise ValueError(
        f"Unknown category '{category}'. Use a known word ({', '.join(sorted(CATEGORY_SELECTORS))}) "
        f"or a raw OSM selector like 'amenity=cafe'."
    )


def _humanize(value: str) -> str:
    return value.replace("_", " ").strip().title()


def parse_element(element: dict) -> Optional[RawBusiness]:
    """Pure Overpass-element -> RawBusiness mapping (no network). Returns ``None`` for unnamed
    elements (a business with no name is not a usable lead)."""
    tags = element.get("tags") or {}
    name = (tags.get("name") or "").strip()
    if name == "":
        return None

    osm_type = element.get("type", "")
    osm_id = element.get("id", "")
    provider_id = f"{osm_type}/{osm_id}" if osm_type and osm_id != "" else str(osm_id)

    lat = element.get("lat")
    lon = element.get("lon")
    if lat is None or lon is None:
        center = element.get("center") or {}
        lat = center.get("lat")
        lon = center.get("lon")

    website = tags.get("website") or tags.get("contact:website") or tags.get("url")
    phone = tags.get("phone") or tags.get("contact:phone") or tags.get("contact:mobile")
    email = tags.get("email") or tags.get("contact:email")

    category = None
    for key in _CATEGORY_KEYS:
        if tags.get(key):
            category = _humanize(tags[key])
            break

    return RawBusiness(
        provider_id=provider_id,
        name=name,
        latitude=float(lat) if lat is not None else None,
        longitude=float(lon) if lon is not None else None,
        website=website.strip() if isinstance(website, str) else None,
        phone=phone.strip() if isinstance(phone, str) else None,
        email=email.strip() if isinstance(email, str) else None,
        category=category,
        # addr:* tags are sparse in PH OSM data; reverse-geocoding fills the gaps in discover().
        region=(tags.get("addr:region") or "").strip() or None,
        province=(tags.get("addr:province") or "").strip() or None,
        city=(tags.get("addr:city") or tags.get("addr:town") or "").strip() or None,
    )


@dataclass
class CellSweep:
    """Every RAW element one volume-mode sweep returned for a cell, and whether the cap cut it off.

    RAW, deliberately. Overpass applies `out center N` to elements before anything is parsed, and
    unnamed elements are dropped only afterwards — so "fewer named businesses than the limit" does not
    mean the cell ran out. Only the raw count against the raw cap says that: fewer raw elements than
    the cap means the whole cell was read.
    """

    elements: list[dict] = field(default_factory=list)
    raw_cap: int = 0

    @property
    def raw_truncated(self) -> bool:
        return self.raw_cap > 0 and len(self.elements) >= self.raw_cap


@dataclass
class SelectorSweep:
    """Businesses found by a selector sweep, plus WHICH selector found each one.

    The attribution is the whole reason this is not a plain list. A sweep unions several selectors
    into ONE Overpass query, so the response cannot say which selector matched what — but per-selector
    net-new is exactly the number that decides whether a selector earns its place. Without it a tag
    that duplicates the baseline entirely (measured: `healthcare=pharmacy` returns twelve businesses
    and zero net-new) would justify itself forever on gross counts.

    Derived from each element's own tags rather than from the query, so it is a fact about the
    element and not a guess about the response.
    """

    businesses: list[RawBusiness] = field(default_factory=list)
    #: "amenity=fast_food" -> the provider_ids that carried that tag.
    by_selector: dict[str, list[str]] = field(default_factory=dict)


def extract_admin_area(address: dict) -> tuple[Optional[str], Optional[str], Optional[str]]:
    """Map a Nominatim ``address`` object to (region, province, city) with sensible fallbacks."""
    region = address.get("region") or address.get("state")
    province = (
        address.get("province")
        or address.get("state_district")
        or address.get("county")
    )
    city = (
        address.get("city")
        or address.get("town")
        or address.get("municipality")
        or address.get("village")
        or address.get("suburb")
    )
    return region, province, city


class OpenStreetMapProvider(DiscoveryProvider):
    name = "openstreetmap"

    def __init__(
        self,
        *,
        overpass_url: str,
        nominatim_url: str,
        user_agent: str,
        timeout: float = 60.0,
        reverse_geocode: bool = True,
        country_code: str | None = None,
        nominatim_state_dir: str | None = None,
    ) -> None:
        self.overpass_url = overpass_url
        self.nominatim_url = nominatim_url.rstrip("/")
        self.user_agent = user_agent
        self.timeout = timeout
        self.reverse_geocode = reverse_geocode
        # ISO 3166-1 alpha-2, lowercased for Nominatim. None means "anywhere", which is the
        # behaviour that predates the country control and is kept for callers that have no country.
        self.country_code = country_code.strip().lower() if country_code else None
        self._client = httpx.Client(timeout=timeout, headers={"User-Agent": user_agent})
        # Host-wide Nominatim spacing + area-geocode cache (see scraper.nominatim_gate). None — the
        # default — keeps the per-process sleep this provider has always used.
        self._nominatim_gate = open_gate(nominatim_state_dir)

    def discover(
        self,
        *,
        area: str,
        category: str,
        limit: int,
        bbox: tuple[float, float, float, float] | None = None,
    ) -> list[RawBusiness]:
        selectors = category_selectors(category)
        # A caller-supplied box wins: that is what makes a partitioned run sweep its own cell instead
        # of re-resolving the whole scope and returning the same records in every one.
        bbox = bbox or self._geocode_area(area)
        elements = self._overpass(selectors, bbox, limit)

        businesses: list[RawBusiness] = []
        for element in elements:
            business = parse_element(element)
            if business is None:
                continue
            if self.reverse_geocode and business.latitude is not None and business.longitude is not None:
                if not (business.region and business.province and business.city):
                    self._fill_admin_area(business)
            businesses.append(business)
            if len(businesses) >= limit:
                break
        return businesses

    def find_by_name(
        self,
        names: list[str],
        *,
        area: str,
        limit: int = 200,
        bbox: tuple[float, float, float, float] | None = None,
    ) -> list[RawBusiness]:
        """Look up businesses BY NAME within ``area``, deliberately without a category filter.

        This is the deterministic half of AI-assisted discovery: something proposes names, this
        proves whether OSM actually holds a business by that name in that area, and only proven ones
        become RawBusiness records with a real ``provider_id`` and real coordinates.

        MATCHING IS PREFIX-ANCHORED, NOT EXACT. See ``_overpass_by_name`` — a proposal retrieves any
        OSM element whose name STARTS with it, which is what lets "Mercury Drug" reach the branch
        rows. Deciding which of those retrieved elements actually correspond to the proposal is the
        resolver's job, not this method's.

        DELIBERATELY UNCONSTRAINED BY CATEGORY, and that is the entire point. Filtering by the same
        CATEGORY_SELECTORS ``discover()`` uses could only ever return a subset of what ``discover()``
        already returns, so the proposer would add exactly nothing. Dropping the constraint is what
        finds the business OSM holds under a tag our 19 selectors do not cover — a pharmacy tagged
        ``shop=chemist`` rather than ``amenity=pharmacy``.

        ONE query for the whole batch, via regex alternation. A query per name would multiply load on
        Overpass by the proposal count; Overpass is shared community infrastructure and this tool is
        a guest on it, so batch cost stays the same order as a normal discover() run.

        Additive: ``discover()`` and every function it calls are untouched by this method's existence.

        A CELL ``bbox`` BOUNDS THE SEARCH. Without one the whole geocoded ``area`` is searched, exactly
        as before. With one, only the cell widened by ``NAME_LOOKUP_MARGIN_DEGREES`` and clipped to the
        area is — because a nationwide cell's area is an anchor LABEL, and a province-sized box made
        this unindexed regex query outlast its own timeout (measured: "Cebu", 61.7s). The bounded query
        also asks Overpass for a shorter server timeout than the client's, and a server-side timeout
        raises instead of passing for "no matches".
        """
        wanted = [n.strip() for n in names if isinstance(n, str) and n.strip()]
        if not wanted:
            return []

        area_box = self._geocode_area(area)
        if bbox is None:
            elements = self._overpass_by_name(wanted, area_box, limit)
        else:
            elements = self._overpass_by_name(
                wanted,
                name_lookup_box(bbox, area_box),
                limit,
                server_timeout=NAME_LOOKUP_SERVER_TIMEOUT_SECONDS,
            )

        businesses: list[RawBusiness] = []
        for element in elements:
            # Prefix matching lets a business's name reach nearby map features that merely quote it —
            # a car park named after the restaurant beside it. Refused here, never in `discover()`,
            # which names the tags it wants and cannot return one.
            if not is_business_element(element):
                continue
            business = parse_element(element)
            if business is None:
                continue
            # Identity came from a real OSM element, not from whoever proposed the name.
            business.verification_source = "osm"
            if self.reverse_geocode and business.latitude is not None and business.longitude is not None:
                if not (business.region and business.province and business.city):
                    self._fill_admin_area(business)
            businesses.append(business)
        return businesses

    def find_by_selectors(
        self, selectors: list[tuple[str, str]], *, area: str, limit: int = 200
    ) -> SelectorSweep:
        """Sweep ``area`` for businesses matching ADDITIONAL tag selectors, with attribution.

        THE EXPANSION LANE'S ONLY OSM CALL. ``discover()`` sweeps the category's hand-written
        selectors and is not touched by this method's existence; this sweeps a separate set and the
        composite merges the two on ``provider_id``. Keeping them as two calls rather than one widened
        call is what leaves the accepted default behaviour byte-identical.

        ONE QUERY FOR EVERY SELECTOR. ``_overpass`` already unions them, so eight expanded tags cost a
        single Overpass request rather than eight — the reason this lane adds about a second rather
        than a minute.

        NO BUSINESS FILTER, deliberately, and unlike ``find_by_name``. A selector sweep is defined by
        its tags exactly as ``discover()`` is, so what it returns is what was asked for. The
        equivalent protection lives one step earlier, where a non-business selector is refused before
        it can be executed at all.
        """
        if not selectors:
            return SelectorSweep()

        bbox = self._geocode_area(area)
        elements = self._overpass(selectors, bbox, limit)

        sweep = SelectorSweep()
        for element in elements:
            business = parse_element(element)
            if business is None:
                continue
            # Identity came from a real OSM element, exactly as on every other path.
            business.verification_source = "osm"
            if self.reverse_geocode and business.latitude is not None and business.longitude is not None:
                if not (business.region and business.province and business.city):
                    self._fill_admin_area(business)
            sweep.businesses.append(business)

            # Attribute from the element's OWN tags: which of the selectors this element satisfies.
            tags = element.get("tags") or {}
            for key, value in selectors:
                if str(tags.get(key) or "").strip().lower() == value:
                    sweep.by_selector.setdefault(f"{key}={value}", []).append(business.provider_id)
        return sweep

    # -- OSM calls -----------------------------------------------------------------------------

    def _overpass_by_name(
        self,
        names: list[str],
        bbox: tuple[float, float, float, float],
        limit: int,
        *,
        server_timeout: int | None = None,
    ) -> list[dict]:
        """One name-filtered Overpass query for a whole batch of names.

        ``server_timeout`` (bounded lookups only) replaces the query's ``[timeout:60]``. Overpass reports
        a query that hit it as HTTP 200 with a ``remark`` and a partial or empty element list — which
        would read as "no business by that name" — so on that path the remark is checked and raises.
        Without it the query and its handling are exactly what they always were.
        """
        south, west, north, east = bbox
        bbox_str = f"{south},{west},{north},{east}"

        # Escape regex metacharacters in the names (a business called "A+B Trading" must not compile
        # into a quantifier), then escape the Overpass string literal itself.
        #
        # ANCHORED AT THE START ONLY, AND THAT ASYMMETRY IS THE POINT. Philippine OSM names routinely
        # carry a branch qualifier the proposer has no way to guess — "Mercury Drug Vigan", "Rose
        # Pharmacy - Quirino Blvd". A trailing `$` made those unretrievable: the element was never
        # fetched, so the resolver never got to judge it, and the businesses a proposer is most likely
        # to name were the ones structurally guaranteed to be missed.
        #
        # The LEADING `^` stays, and does the safety work: "Drug" still cannot match "Mercury Drug",
        # so a short or generic word cannot sweep in unrelated businesses. Widening happens only in
        # the direction real names actually vary — extra words on the END.
        alternation = "|".join(re.escape(n) for n in names)
        pattern = f"^({alternation})".replace("\\", "\\\\").replace('"', '\\"')

        parts = []
        for kind in ("node", "way"):
            # `,i` = case-insensitive: OSM casing is inconsistent and a proposer's casing is a guess.
            parts.append(f'{kind}["name"~"{pattern}",i]({bbox_str});')
        seconds = 60 if server_timeout is None else int(server_timeout)
        query = f"[out:json][timeout:{seconds}];({''.join(parts)});out center {limit};"

        resp = self._post_with_retry(query)
        payload = resp.json()
        if server_timeout is not None:
            remark = str(payload.get("remark") or "")
            if "runtime error" in remark.lower():
                raise RuntimeError(f"Overpass name lookup failed on the server: {remark[:200]}")
        return payload.get("elements", [])

    def geocode_area(self, area: str) -> tuple[float, float, float, float]:
        """Resolve a place name to a bounding box — the PUBLIC geocoding seam.

        Exposed because geocoding is the one genuinely provider-neutral thing this class does: the
        partitioner needs a scope's box before any provider runs, and the Google provider needs the
        same box an OSM sweep would use. Both previously reached for the private method, which made
        a shared utility look like an implementation detail being violated.
        """
        return self._geocode_area(area)

    def _geocode_area(self, area: str) -> tuple[float, float, float, float]:
        """Forward-geocode a place name to a bounding box (south, west, north, east).

        THE COUNTRY IS ENFORCED HERE, AND THIS IS THE RIGHT PLACE FOR IT.

        Every provider reaches its search area through this method — the Overpass sweep bounds its
        query with it, and the Google adapter turns it into `locationRestriction`. So a place that
        cannot be geocoded is a place that cannot be searched: constraining the geocoder to one
        country makes a foreign location structurally unable to become a provider request, rather
        than a request whose results are thrown away afterwards. That distinction is the difference
        between "we do not keep foreign businesses" and "we do not go looking for them", and only the
        second one is free.

        THE BUG THIS CLOSES IS NOT HYPOTHETICAL. Unconstrained, "San Jose" resolves to San Jose,
        California — not to any of the several San Joses in the Philippines — because Nominatim ranks
        by prominence, not by the caller's intent. A discovery run for "San Jose" therefore searched
        California, paid for the Google call, and ingested twenty Californian businesses into a
        Philippine prospect pool. A country filter is what makes an ambiguous place name resolve to
        the one the user actually meant.

        AMBIGUITY BECOMES A REFUSAL, NEVER A GUESS. With `countrycodes` set, Nominatim returns
        nothing for a place that does not exist in that country, and this raises — the caller reports
        an unresolved area and performs no search. An empty result is a far better outcome than a
        confident search of the wrong continent.
        """
        params: dict[str, object] = {"q": area, "format": "jsonv2", "limit": 1}
        if self.country_code:
            params["countrycodes"] = self.country_code

        if self._nominatim_gate is not None:
            return self._geocode_area_gated(area, params)

        _pace_nominatim()  # Nominatim usage policy: <=1 req/sec, start-to-start
        resp = self._client.get(f"{self.nominatim_url}/search", params=params)
        resp.raise_for_status()
        results = resp.json()
        if not results:
            where = f" in country '{self.country_code.upper()}'" if self.country_code else ""
            raise ValueError(f"Nominatim could not resolve area '{area}'{where}.")
        south, north, west, east = (float(x) for x in results[0]["boundingbox"])
        return south, west, north, east

    def _geocode_area_gated(self, area: str, params: dict[str, object]) -> tuple[float, float, float, float]:
        """``_geocode_area`` through the shared gate: a cached answer costs no request at all, and a
        real request takes the host-wide slot instead of sleeping per process. Same query, same
        parsing, same refusal for an unresolvable area — which is never cached."""
        gate = self._nominatim_gate
        assert gate is not None
        key = geocode_cache_key(self.nominatim_url, self.country_code, area)
        cached = gate.cached_bbox(key)
        if cached is not None:
            return cached

        with gate.request_slot():
            resp = self._client.get(f"{self.nominatim_url}/search", params=params)
            resp.raise_for_status()
            results = resp.json()
        if not results:
            where = f" in country '{self.country_code.upper()}'" if self.country_code else ""
            raise ValueError(f"Nominatim could not resolve area '{area}'{where}.")
        south, north, west, east = (float(x) for x in results[0]["boundingbox"])
        gate.store_bbox(key, (south, west, north, east))
        return south, west, north, east

    def _overpass(
        self, selectors: list[tuple[str, str]], bbox: tuple[float, float, float, float], limit: int
    ) -> list[dict]:
        south, west, north, east = bbox
        bbox_str = f"{south},{west},{north},{east}"
        parts = []
        for key, value in selectors:
            parts.append(f'node["{key}"="{value}"]({bbox_str});')
            parts.append(f'way["{key}"="{value}"]({bbox_str});')
        query = f"[out:json][timeout:60];({''.join(parts)});out center {limit};"

        resp = self._post_with_retry(query)
        return resp.json().get("elements", [])

    # Transient Overpass responses (rate limit / gateway) worth retrying with backoff.
    _RETRY_STATUS = {406, 429, 502, 503, 504}

    def national_points(
        self, selectors: list[tuple[str, str]], *, country_code: str
    ) -> list[tuple[float, float]]:
        """Where every NAMED match for ``selectors`` sits in a country — positions only.

        {@see national_places} without the ids: the same single query, the same points.
        """
        return [(lat, lon) for lat, lon, _ in self.national_places(selectors, country_code=country_code)]

    def national_places(
        self, selectors: list[tuple[str, str]], *, country_code: str
    ) -> list[tuple[float, float, str]]:
        """Where every NAMED match for ``selectors`` sits in a country — position and OSM key.

        THE DENSITY PROBE. One query over the country's own OSM boundary (ISO 3166-1, resolved by
        Overpass, never a list of places) returns the position of every element the per-cell sweep
        could ever return: same selectors, node and way, named only because ``parse_element`` drops
        unnamed elements. Counted into the planner's cells, that is exactly what each cell's own
        search would find — verified cell-by-cell against direct queries (657/657, 25/25, 3/3).

        ``out skel center`` carries positions and ids and nothing else, so no business data travels
        here and nothing is ever ingested from it: it decides WHERE to look first, never WHAT is found.
        Transient refusals are retried exactly as every other Overpass call is.
        """
        if not _COUNTRY_CODE.match(country_code or ""):
            raise ValueError(f"Country must be an ISO 3166-1 alpha-2 code, got {country_code!r}.")
        parts = []
        for key, value in selectors:
            parts.append(f'node["{key}"="{value}"][name](area.c);')
            parts.append(f'way["{key}"="{value}"][name](area.c);')
        query = (
            f'[out:json][timeout:180];area["ISO3166-1"="{country_code.upper()}"][admin_level=2]->.c;'
            f"({''.join(parts)});out skel center qt;"
        )
        resp = self._post_with_retry(query, timeout=NATIONAL_PROBE_TIMEOUT_SECONDS)
        places: list[tuple[float, float, str]] = []
        for element in resp.json().get("elements", []):
            lat = element.get("lat", (element.get("center") or {}).get("lat"))
            lon = element.get("lon", (element.get("center") or {}).get("lon"))
            if lat is None or lon is None:
                continue
            # The same `type/id` key `parse_element` gives a business, so the CRM can tell which of
            # these it already holds. A key only — still no business data.
            key = f"{element.get('type', '')}/{element.get('id', '')}"
            # Five decimals is ~1 m: far finer than any cell, and a smaller payload to hand the CRM.
            places.append((round(float(lat), 5), round(float(lon), 5), key))
        return places

    def sweep_cell(
        self, selectors: list[tuple[str, str]], bbox: tuple[float, float, float, float], raw_cap: int
    ) -> CellSweep:
        """VOLUME MODE's one Overpass query for a cell: up to ``raw_cap`` raw elements, tags included.

        The same union-of-selectors query ``_overpass`` sends, with a larger cap and a client timeout
        longer than the server's. Separate from ``discover()`` so the default sweep is untouched.
        """
        south, west, north, east = bbox
        bbox_str = f"{south},{west},{north},{east}"
        parts = []
        for key, value in selectors:
            parts.append(f'node["{key}"="{value}"]({bbox_str});')
            parts.append(f'way["{key}"="{value}"]({bbox_str});')
        query = f"[out:json][timeout:60];({''.join(parts)});out center {int(raw_cap)};"

        resp = self._post_with_retry(query, timeout=CELL_SWEEP_TIMEOUT_SECONDS)
        return CellSweep(elements=list(resp.json().get("elements", [])), raw_cap=int(raw_cap))

    def _post_with_retry(
        self, query: str, attempts: int = 4, *, timeout: float | None = None
    ) -> httpx.Response:
        """POST an Overpass query, retrying transient rate-limit/gateway responses with backoff."""
        delay = 5.0
        last_status = None
        for attempt in range(1, attempts + 1):
            # A per-request timeout only when the caller asks for one (the density probe); every
            # existing call sends exactly what it always did and keeps the client's own timeout.
            extra = {"timeout": timeout} if timeout is not None else {}
            resp = self._client.post(
                self.overpass_url, content=query, headers={"Content-Type": "text/plain"}, **extra
            )
            if resp.status_code not in self._RETRY_STATUS:
                resp.raise_for_status()
                return resp
            last_status = resp.status_code
            if attempt < attempts:
                time.sleep(delay)
                delay *= 2  # exponential backoff
        raise RuntimeError(
            f"Overpass API is rate-limiting or unavailable (last status {last_status}) after "
            f"{attempts} attempts. Try a smaller --limit or wait and retry."
        )

    def _fill_admin_area(self, business: RawBusiness) -> None:
        if self._nominatim_gate is not None:
            try:
                with self._nominatim_gate.request_slot():
                    resp = self._client.get(
                        f"{self.nominatim_url}/reverse",
                        params={
                            "lat": business.latitude,
                            "lon": business.longitude,
                            "format": "jsonv2",
                        },
                    )
                    resp.raise_for_status()
                    address = resp.json().get("address") or {}
            except (httpx.HTTPError, ValueError):
                return
            region, province, city = extract_admin_area(address)
            business.region = business.region or region
            business.province = business.province or province
            business.city = business.city or city
            return

        _pace_nominatim()  # Nominatim usage policy: <=1 req/sec, start-to-start
        try:
            resp = self._client.get(
                f"{self.nominatim_url}/reverse",
                params={
                    "lat": business.latitude,
                    "lon": business.longitude,
                    "format": "jsonv2",
                },
            )
            resp.raise_for_status()
            address = resp.json().get("address") or {}
        except (httpx.HTTPError, ValueError):
            return

        region, province, city = extract_admin_area(address)
        business.region = business.region or region
        business.province = business.province or province
        business.city = business.city or city

    def close(self) -> None:
        self._client.close()
