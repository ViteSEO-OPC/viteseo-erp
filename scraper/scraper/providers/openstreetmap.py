"""OpenStreetMap discovery provider (v1).

Discovers businesses with the Overpass API and resolves administrative location with Nominatim.
Uses only open data — no API key, no billing. This is simply the *first* ``DiscoveryProvider``; a
Google Places provider can be added later with no change outside this package.

OSM etiquette honored: a descriptive User-Agent, and Nominatim reverse-geocoding throttled to <=1
request/second per the usage policy.
"""

from __future__ import annotations

import re
import time
from typing import Optional

import httpx

from scraper.models import RawBusiness
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
    ) -> None:
        self.overpass_url = overpass_url
        self.nominatim_url = nominatim_url.rstrip("/")
        self.user_agent = user_agent
        self.timeout = timeout
        self.reverse_geocode = reverse_geocode
        self._client = httpx.Client(timeout=timeout, headers={"User-Agent": user_agent})

    def discover(self, *, area: str, category: str, limit: int) -> list[RawBusiness]:
        selectors = category_selectors(category)
        bbox = self._geocode_area(area)
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

    def find_by_name(self, names: list[str], *, area: str, limit: int = 200) -> list[RawBusiness]:
        """Look up businesses BY NAME within ``area``, deliberately without a category filter.

        This is the deterministic half of AI-assisted discovery: something proposes names, this
        proves whether OSM actually holds a business by that name in that area, and only proven ones
        become RawBusiness records with a real ``provider_id`` and real coordinates.

        DELIBERATELY UNCONSTRAINED BY CATEGORY, and that is the entire point. Filtering by the same
        CATEGORY_SELECTORS ``discover()`` uses could only ever return a subset of what ``discover()``
        already returns, so the proposer would add exactly nothing. Dropping the constraint is what
        finds the business OSM holds under a tag our 19 selectors do not cover — a pharmacy tagged
        ``shop=chemist`` rather than ``amenity=pharmacy``.

        ONE query for the whole batch, via regex alternation. A query per name would multiply load on
        Overpass by the proposal count; Overpass is shared community infrastructure and this tool is
        a guest on it, so batch cost stays the same order as a normal discover() run.

        Additive: ``discover()`` and every function it calls are untouched by this method's existence.
        """
        wanted = [n.strip() for n in names if isinstance(n, str) and n.strip()]
        if not wanted:
            return []

        bbox = self._geocode_area(area)
        elements = self._overpass_by_name(wanted, bbox, limit)

        businesses: list[RawBusiness] = []
        for element in elements:
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

    # -- OSM calls -----------------------------------------------------------------------------

    def _overpass_by_name(
        self, names: list[str], bbox: tuple[float, float, float, float], limit: int
    ) -> list[dict]:
        """One name-filtered Overpass query for a whole batch of names."""
        south, west, north, east = bbox
        bbox_str = f"{south},{west},{north},{east}"

        # Escape regex metacharacters in the names (a business called "A+B Trading" must not compile
        # into a quantifier), then escape the Overpass string literal itself.
        alternation = "|".join(re.escape(n) for n in names)
        pattern = f"^({alternation})$".replace("\\", "\\\\").replace('"', '\\"')

        parts = []
        for kind in ("node", "way"):
            # `,i` = case-insensitive: OSM casing is inconsistent and a proposer's casing is a guess.
            parts.append(f'{kind}["name"~"{pattern}",i]({bbox_str});')
        query = f"[out:json][timeout:60];({''.join(parts)});out center {limit};"

        resp = self._post_with_retry(query)
        return resp.json().get("elements", [])

    def _geocode_area(self, area: str) -> tuple[float, float, float, float]:
        """Forward-geocode a place name to a bounding box (south, west, north, east)."""
        resp = self._client.get(
            f"{self.nominatim_url}/search",
            params={"q": area, "format": "jsonv2", "limit": 1},
        )
        resp.raise_for_status()
        results = resp.json()
        if not results:
            raise ValueError(f"Nominatim could not resolve area '{area}'.")
        south, north, west, east = (float(x) for x in results[0]["boundingbox"])
        time.sleep(1.1)  # Nominatim usage policy: <=1 req/sec
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

    def _post_with_retry(self, query: str, attempts: int = 4) -> httpx.Response:
        """POST an Overpass query, retrying transient rate-limit/gateway responses with backoff."""
        delay = 5.0
        last_status = None
        for attempt in range(1, attempts + 1):
            resp = self._client.post(
                self.overpass_url, content=query, headers={"Content-Type": "text/plain"}
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
        finally:
            time.sleep(1.1)  # Nominatim usage policy: <=1 req/sec

        region, province, city = extract_admin_area(address)
        business.region = business.region or region
        business.province = business.province or province
        business.city = business.city or city

    def close(self) -> None:
        self._client.close()
