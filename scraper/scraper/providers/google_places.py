"""Google Places (API New) discovery — Text Search, made by the CRM.

    OSM geocoder (area -> bounding box)  ->  CRM (holds the Google key, calls Text Search)  ->  RawBusiness

THE CREDENTIAL IS NOT HERE. Exactly like the AI provider, this process never holds a Google key: it
asks the CRM for businesses over the authenticated channel it already uses to ingest leads, and the
CRM makes the Places call with the credential an admin stored in Settings -> Integrations.

AN ADDITIONAL PROVIDER, NOT A REPLACEMENT. OpenStreetMap stays the default and the fallback. The only
OSM code used here is its geocoder, so the area is resolved to the same bounding box an OSM run would
sweep — Google is then RESTRICTED to that box, which is what stops results drifting outside the area.

IDENTITY is Google's own stable place id, namespaced ``places/<id>`` (see ``RawBusiness``), so a
Google key can never collide with an OSM ``node/123`` key. Two providers finding the SAME business
therefore produce two different keys — the cross-provider duplicate risk is real and deliberately not
solved here (no fuzzy name/proximity matching yet).

CATEGORIES speak the OSM provider's vocabulary. A Places type is mapped to the OSM tag value an OSM
run would have produced (``hair_salon`` -> ``Hairdresser``), so the Nearby niche filter, which is
built from the discovered pool, does not grow a synonym per provider.

FAILURE IS EMPTY, NEVER WRONG — and never silent: every failure carries an execution status, and only
``ok`` means the list is a real answer.
"""

from __future__ import annotations

from typing import Any, Optional

import httpx

from scraper.models import RawBusiness
from scraper.providers.base import DiscoveryProvider
from scraper.providers.openstreetmap import OpenStreetMapProvider, _humanize

EXECUTION_OK = "ok"
#: The CRM could not be reached at all (network, timeout, unreadable response).
EXECUTION_CRM_UNREACHABLE = "crm_unreachable"
#: The CRM refused because Google Places is not the effective provider (409) — configuration, not an error.
EXECUTION_NOT_EFFECTIVE = "not_effective_provider"
#: A response we could not interpret. A failure, not an empty result.
EXECUTION_INVALID_RESPONSE = "invalid_response"
#: The area could not be geocoded, so there is no bounding box to restrict the search to.
EXECUTION_AREA_UNRESOLVED = "area_unresolved"
#: The search was refused for RATE, not for any fact about the area — by Google (the CRM relays its
#: own ``rate_limited``) or by the CRM's own callback throttle (a bare HTTP 429). It says nothing about
#: whether businesses exist there, so it must never be read as an empty or exhausted area; the caller
#: may retry later. Same word the CRM uses, so both sources read identically downstream.
EXECUTION_RATE_LIMITED = "rate_limited"

#: Upper bound on a server-supplied Retry-After we will pass on. A larger value is clamped rather than
#: trusted, so a misbehaving header cannot stall a run.
MAX_RETRY_AFTER_SECONDS = 300

#: Nearby category -> Places API (New) type, sent as ``includedType`` with strict type filtering.
#: Only categories with ONE exact Places type are listed. ``salon`` (hairdresser OR beauty) and
#: ``clinic`` have none, so they are searched by text alone rather than by a type that would narrow them.
INCLUDED_TYPES: dict[str, str] = {
    "cafe": "cafe",
    "restaurant": "restaurant",
    "fast_food": "fast_food_restaurant",
    "bakery": "bakery",
    "bar": "bar",
    "hotel": "hotel",
    "dentist": "dentist",
    "doctor": "doctor",
    "pharmacy": "pharmacy",
    "spa": "spa",
    "gym": "gym",
    "car_repair": "car_repair",
    "hardware": "hardware_store",
    "supermarket": "supermarket",
    "real_estate": "real_estate_agency",
    "travel_agency": "travel_agency",
    "school": "school",
}

#: Places type -> the OpenStreetMap tag VALUE the OSM provider derives ``category`` from. Mapped to the
#: OSM value (not a new label) so both providers humanize to the identical string.
OSM_EQUIVALENT_VALUES: dict[str, str] = {
    "pharmacy": "pharmacy",
    "drugstore": "pharmacy",
    "cafe": "cafe",
    "coffee_shop": "cafe",
    "restaurant": "restaurant",
    "fast_food_restaurant": "fast_food",
    "bakery": "bakery",
    "bar": "bar",
    "hotel": "hotel",
    "dentist": "dentist",
    "dental_clinic": "dentist",
    "doctor": "doctors",
    "hair_salon": "hairdresser",
    "barber_shop": "hairdresser",
    "beauty_salon": "beauty",
    "spa": "spa",
    "massage": "massage",
    "gym": "fitness_centre",
    "fitness_center": "fitness_centre",
    "car_repair": "car_repair",
    "hardware_store": "hardware",
    "supermarket": "supermarket",
    "real_estate_agency": "estate_agent",
    "travel_agency": "travel_agency",
    "school": "school",
}

#: Google's catch-all types. They describe nothing a BD would filter on, so they never become a category.
_GENERIC_TYPES = {"point_of_interest", "establishment", "store", "food", "health", "service"}

#: Planned query phrases sent for one task. Mirrors the CRM's own ceiling — the authority is the CRM,
#: which caps and de-duplicates whatever arrives; this simply avoids sending what would be discarded.
MAX_PLANNED_QUERIES = 3


def category_for(primary_type: Optional[str], types: list[str] | None) -> Optional[str]:
    """The Nearby ``category`` for a Places record, in the OSM provider's vocabulary."""
    ordered = [t for t in ([primary_type] if primary_type else []) + list(types or []) if t]

    for place_type in ordered:
        if place_type in OSM_EQUIVALENT_VALUES:
            return _humanize(OSM_EQUIVALENT_VALUES[place_type])
        # Places has dozens of cuisine types (`filipino_restaurant`); OSM tags them all `restaurant`.
        if place_type.endswith("_restaurant"):
            return _humanize("restaurant")

    for place_type in ordered:
        if place_type not in _GENERIC_TYPES:
            return _humanize(place_type)

    return None


def _retry_after_seconds(value: Optional[str]) -> Optional[int]:
    """A Retry-After header in whole seconds, clamped; None when absent or not a delay in seconds."""
    if value is None:
        return None
    text = value.strip()
    if not text.isdigit():
        # The HTTP-date form is legal but neither the CRM nor Google sends it; ignore rather than parse.
        return None
    return min(int(text), MAX_RETRY_AFTER_SECONDS)


class GooglePlacesProvider(DiscoveryProvider):
    """Discovers businesses with Google Places Text Search, through the CRM."""

    name = "google_places"

    def __init__(
        self,
        *,
        crm_base_url: str,
        crm_token: str,
        timeout: float,
        osm: OpenStreetMapProvider,
        use_strategy: bool = False,
    ) -> None:
        if not crm_token:
            # Loud, at construction — the CRM holds the Google credential, so without a CRM token this
            # provider cannot run at all, and silently running OSM instead would mislabel the run.
            raise ValueError(
                "CRM_TOKEN is required for --provider google_places (the CRM holds the Google key "
                "and makes the call). Set it, or use --provider osm."
            )
        self.crm_base_url = crm_base_url.rstrip("/")
        self.crm_token = crm_token
        self._osm = osm
        self._timeout = timeout
        # OPT-IN, never implicit. Google Places is a FACTUAL provider and must be able to run with no
        # AI involvement at all, so a Google-only run makes no model call unless one is explicitly
        # requested. The planner stays fully built and reachable for the future AI-enabled flow —
        # pass `use_strategy=True` (see `build_discovery_provider`, driven by DISCOVERY_AI_STRATEGY).
        self._use_strategy = use_strategy
        self._client = httpx.Client(timeout=timeout)
        self._stats: dict[str, Any] = {}
        # How THIS task's planning call ended, or None when planning was not consulted. Reported
        # beside the task's stats so the CRM can tell "no credit" from "AI switched off".
        self._strategy_status: Optional[str] = None

    def discover(
        self,
        *,
        area: str,
        category: str,
        limit: int,
        bbox: Optional[tuple[float, float, float, float]] = None,
    ) -> list[RawBusiness]:
        self._strategy_status = None
        if bbox is not None:
            # Already resolved by the partitioner — this call searches ONE cell, not the scope.
            south, west, north, east = bbox
        else:
            try:
                # The OSM provider's public geocoder, so Google searches the same box an OSM run
                # would sweep.
                south, west, north, east = self._osm.geocode_area(area)
            except (httpx.HTTPError, ValueError) as exc:
                return self._failed(EXECUTION_AREA_UNRESOLVED, type(exc).__name__)

        payload: dict[str, Any] = {
            "area": area,
            "category": category,
            "limit": limit,
            "bbox": {"south": south, "west": west, "north": north, "east": east},
        }
        # THE DETERMINISTIC BASELINE. The hand-written table is the authority on place TYPE: it is
        # exact, free, and proven against live data, so AI is never allowed to weaken it.
        included_type = INCLUDED_TYPES.get(category.strip().lower())

        # AI IS CONSULTED FOR EVERY CATEGORY, NOT ONLY THE ONES THE TABLE MISSES.
        #
        # It used to run only as a fallback for unmapped niches — which meant it never ran for ANY
        # configured target, because every configured category is in the table. The capability
        # existed and contributed exactly nothing. It is an ENHANCER, not a substitute: the static
        # type keeps constraining WHAT is matched, while AI refines HOW the category is phrased.
        #
        # Consulted once per task and still ONE Google request: this adds no provider calls.
        plan = self._strategy(area=area, category=category, limit=limit)

        if included_type:
            payload["includedType"] = included_type
        elif plan is not None and plan.place_types:
            # Only where the table has nothing. AI fills a gap; it never overrides a known mapping.
            payload["includedType"] = plan.place_types[0]

        planned_queries: list[str] = []
        if plan is not None and plan.queries:
            # BOUNDED QUERY VARIATION. A niche approached from a few real angles finds businesses one
            # phrasing misses, but a query count multiplies paid provider calls — so it is capped
            # here as well as in the CRM, which de-duplicates and enforces its own ceiling. The
            # caller's `limit` is NOT multiplied: the CRM treats it as a total across all queries.
            planned_queries = plan.queries[:MAX_PLANNED_QUERIES]
            payload["textQueries"] = planned_queries

        strategy_applied = plan is not None and bool(plan.queries or plan.place_types)

        try:
            response = self._client.post(
                f"{self.crm_base_url}/discovery/places",
                headers={"Authorization": f"Bearer {self.crm_token}", "Accept": "application/json"},
                json=payload,
            )
        except httpx.HTTPError as exc:
            return self._failed(EXECUTION_CRM_UNREACHABLE, type(exc).__name__)

        data: dict[str, Any] = {}
        try:
            body = response.json()
            if isinstance(body, dict) and isinstance(body.get("data"), dict):
                data = body["data"]
        except ValueError:
            data = {}

        if response.status_code >= 400:
            status = str(data.get("executionStatus") or "")
            if not status:
                if response.status_code == 429:
                    # The CRM's own throttle answers with no discovery body. It was mislabelled
                    # `crm_unreachable`, which read as an outage and — worse — as a cell with nothing
                    # in it. It is a rate refusal: nothing was searched.
                    status = EXECUTION_RATE_LIMITED
                elif response.status_code == 409:
                    status = EXECUTION_NOT_EFFECTIVE
                else:
                    status = EXECUTION_CRM_UNREACHABLE
            failed = self._failed(status, str(data.get("errorReason") or f"http_{response.status_code}"))
            if status == EXECUTION_RATE_LIMITED:
                retry_after = _retry_after_seconds(response.headers.get("Retry-After"))
                if retry_after is not None:
                    self._stats["retry_after"] = retry_after
            return failed

        status = str(data.get("executionStatus") or EXECUTION_OK)
        if status != EXECUTION_OK:
            return self._failed(status, str(data.get("errorReason") or ""))

        rows = data.get("businesses")
        if not isinstance(rows, list):
            return self._failed(EXECUTION_INVALID_RESPONSE, "missing_businesses")

        businesses = [b for b in (self._to_raw(row) for row in rows) if b is not None][:limit]
        self._stats = {
            "execution_status": EXECUTION_OK,
            "error_reason": data.get("errorReason"),
            "returned": len(businesses),
            "pages": data.get("pages"),
            # What was actually SENT, which is not always the static value now that AI can fill a gap.
            "included_type": payload.get("includedType"),
            # Planned vs actually paid for: the CRM stops early once the limit is met, so these differ
            # whenever a later query was not needed. Only the second one is a cost.
            "queries_planned": len(planned_queries),
            "queries_executed": data.get("queriesExecuted"),
            # Whether AI actually shaped this search. Recorded because "AI is enabled" and "AI
            # influenced the query" are different facts, and only the second one can be judged.
            "strategy_applied": strategy_applied,
        }
        return businesses

    def _strategy(self, *, area: str, category: str, limit: int):
        """The optional AI search plan for THIS TARGET. ``None`` whenever the capability is absent.

        The whole target context travels, not just a category word: the real area (so a plan can
        suit the actual place — trade names and phrasing differ by region) and the run's limit (so
        the breadth of the plan can be calibrated). Both come from the discovery task itself, so
        they are always the values this run is genuinely searching for, never a configured default.

        Imported lazily and failure-tolerant by construction: Google Places is an INDEPENDENT
        provider, so every AI outcome — no credential, disabled, no credit, an older CRM without the
        endpoint — must leave this provider working exactly as it does with no AI at all.
        """
        if not self._use_strategy:
            return None

        from scraper.strategy import fetch_strategy

        report: dict[str, Any] = {}
        plan = fetch_strategy(
            crm_base_url=self.crm_base_url,
            crm_token=self.crm_token,
            category=category,
            scope=area,
            limit=limit,
            timeout=self._timeout,
            report=report,
        )
        self._strategy_status = report.get("status")
        return plan

    @staticmethod
    def _to_raw(row: Any) -> Optional[RawBusiness]:
        if not isinstance(row, dict):
            return None
        provider_id = row.get("provider_id")
        name = row.get("name")
        # Only a Google-namespaced key is accepted: anything else could collide with an OSM identity.
        if not isinstance(provider_id, str) or not provider_id.startswith("places/"):
            return None
        if not isinstance(name, str) or not name.strip():
            return None

        def text(key: str) -> Optional[str]:
            value = row.get(key)
            return value.strip() or None if isinstance(value, str) else None

        def number(key: str) -> Optional[float]:
            value = row.get(key)
            return float(value) if isinstance(value, (int, float)) and not isinstance(value, bool) else None

        types = row.get("types")
        return RawBusiness(
            provider_id=provider_id,
            name=name.strip(),
            latitude=number("latitude"),
            longitude=number("longitude"),
            website=text("website"),
            phone=text("phone"),
            category=category_for(text("primary_type"), [t for t in types if isinstance(t, str)] if isinstance(types, list) else []),
            region=text("region"),
            province=text("province"),
            city=text("city"),
        )

    def _failed(self, status: str, error: Optional[str]) -> list[RawBusiness]:
        self._stats = {"execution_status": status, "error_reason": error, "returned": 0}
        return []

    def stats(self) -> dict[str, Any]:
        """Per-run numbers for the metrics record. Read optionally by the CLI, like ``close()``."""
        out = dict(self._stats)
        # Only when planning was actually consulted, and on failure paths too: a search that failed
        # after its planning call was refused for credit must still say so.
        if self._strategy_status is not None:
            out["strategy_status"] = self._strategy_status
        return out

    def close(self) -> None:
        self._client.close()
        self._osm.close()
