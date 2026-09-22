"use client";

import { useCallback, useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { centroid, haversineKm, type LatLng } from "@/modules/crm/features/nearby-clients/lib/distance";
import {
  geolocationPreflight,
  geolocationStatusFor,
  type GeolocationStatus,
} from "@/modules/crm/features/nearby-clients/lib/geolocation";
import type { NearbyClient } from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";

/** Where the search radius is measured from. NEVER derived from the whole dataset's centroid. */
export type NearbyOrigin = {
  lat: number;
  lng: number;
  /** Human label for the origin (a place name or "My location"). */
  label: string;
  /**
   * How the origin was chosen — in the product's order of preference:
   *  - `location`    → a user-selected target location (a place from the picker)
   *  - `geolocation` → the browser's device position (permission granted)
   *  - `custom`      → a user-entered location (future: free-text geocode / stored BD territory)
   */
  kind: "location" | "geolocation" | "custom";
};

export type NearbyFilters = {
  search: string;
  /** Search radius in km, measured from the selected `origin`. Only applies when an origin is set. */
  radiusKm: number;
  niche: string; // "" = all
  status: string; // "" = all
  /** Derived prospecting priority label ("High" | "Medium" | "Low"); "" = all. */
  priority: string;
  /** Discovery lane label ("OpenStreetMap" | "AI-assisted"); "" = all sources. */
  foundBy: string;
};

/** Radius options (km), measured from the selected origin. */
export const RADIUS_OPTIONS = [5, 10, 25, 50] as const;

// `foundBy: ""` is what makes the default board identical to before this filter existed: an empty
// value skips the predicate entirely rather than selecting a lane.
const INITIAL: NearbyFilters = {
  search: "",
  radiusKm: 10,
  niche: "",
  status: "",
  priority: "",
  foundBy: "",
};

// The status vocabulary and the mapping that produces it live in `lib/geolocation`, so the decision
// is a pure function the gates harness can drive instead of logic trapped inside a React callback.
// Re-exported here because this hook has always been where consumers import it from.
export type { GeolocationStatus } from "@/modules/crm/features/nearby-clients/lib/geolocation";

/**
 * Nearby clients (discovered businesses) + filter state. The returned `clients` drive BOTH the list
 * and the map markers, so the two are always in sync.
 *
 * **Location-driven search origin (never a dataset centroid).** The distance radius is measured from
 * an explicitly chosen `origin` — a user-selected target location or the browser's geolocation — so
 * the feature works identically whether the discovered businesses sit in one city or span the whole
 * country. When NO origin is chosen the view shows *every* matching business (the map fits them all):
 * the default is never empty, even across multiple regions. The radius only ever narrows results
 * relative to a deliberately selected origin — it is never derived from where the data happens to be.
 */
export function useNearbyClients() {
  const [filters, setFilters] = useState<NearbyFilters>(INITIAL);
  const [origin, setOrigin] = useState<NearbyOrigin | null>(null);
  const [geoStatus, setGeoStatus] = useState<GeolocationStatus>("idle");

  const query = useQuery<NearbyClient[]>({
    queryKey: queryKeys.nearbyClients.list(),
    queryFn: () => apiClient.get("/nearby-clients").then((r) => r.data.data as NearbyClient[]),
  });

  // Humanize the status enum once so options / display / filtering all use the same readable label.
  /**
   * The pool this board shows: every discovered business EXCEPT the ones the national boundary
   * places outside the Philippines.
   *
   * NEARBY CLIENTS IS A PHILIPPINE PROSPECTING SURFACE. Twenty leads in San Jose, California
   * predate the discovery fence — real records, created when an unconstrained geocoder resolved
   * "San Jose" to the wrong continent — and they were drawing their own cluster in North America on
   * a map of the Philippines. They are NOT deleted and NOT modified: the API still serves them and
   * the rows are untouched in the database; they simply are not part of this view.
   *
   * `inPhilippines === false` ONLY. `null` means the lead has no coordinates, which is not evidence
   * of a foreign one — the ingest applies exactly that rule — and a third of the pool has no
   * position, so treating null as foreign would empty the board.
   *
   * Filtered HERE, in the display layer, rather than in the pool query: `NearbyClientController` and
   * its scope are frozen, and this needs no change to either.
   */
  const all = useMemo(
    () =>
      (query.data ?? [])
        .filter((c) => c.inPhilippines !== false)
        .map((c) => ({ ...c, status: formatStatus(c.status) })),
    [query.data],
  );

  const options = useMemo(
    () => ({
      // Selectable target locations (the discovered admin areas) — each becomes a search origin.
      locations: distinct(all.map((c) => c.area)),
      niches: distinct(all.map((c) => c.niche)),
      statuses: distinct(all.map((c) => c.status)),
      // ALWAYS the full vocabulary, never narrowed to what the pool currently holds.
      //
      // Unlike niche/status/location — open-ended values that only mean something if a record has
      // them — prospecting priority is a CLOSED, derived set of exactly three levels
      // (NearbyClientResource::prospectingPriority maps website_status → high|medium|low, defaulting
      // to medium). A BD filtering a pool that happens to be all-High still needs Medium and Low
      // present to understand the axis and to keep the control stable as discovery adds records.
      // Deriving it from the data made the dropdown's shape depend on discovery timing.
      priorities: [...PRIORITY_ORDER],
      // Closed set, always offered in full — for the same reason priorities are. A BD whose pool
      // happens to hold no AI-found businesses still needs the option present to learn the axis
      // exists, and the control must not change shape as discovery runs.
      foundBy: [...FOUND_BY_ORDER],
    }),
    [all],
  );

  // Candidate set — matches the text/category filters. NOT geographically filtered: the radius (below)
  // is the only geographic filter, and it applies solely relative to the chosen origin.
  const candidates = useMemo(() => {
    const q = filters.search.trim().toLowerCase();
    return all
      .filter((c) => (filters.niche ? c.niche === filters.niche : true))
      .filter((c) => (filters.status ? c.status === filters.status : true))
      .filter((c) => (filters.priority ? formatPriority(c.priority) === filters.priority : true))
      .filter((c) => (filters.foundBy ? formatFoundBy(c.foundBy) === filters.foundBy : true))
      .filter((c) =>
        q ? c.business.toLowerCase().includes(q) || c.contact.toLowerCase().includes(q) : true,
      );
  }, [all, filters.search, filters.niche, filters.status, filters.priority, filters.foundBy]);

  // The same candidate set with the PRIORITY predicate removed, and nothing else changed.
  //
  // Its only job is to answer one question honestly: would dropping the priority filter have shown
  // anything? Without it the UI can see that the list is empty but not why, and an unsatisfiable
  // priority reads exactly like a discovery that found nothing.
  const matchesIgnoringPriority = useMemo(() => {
    if (!filters.priority) {
      return 0;
    }
    const q = filters.search.trim().toLowerCase();
    return all
      .filter((c) => (filters.niche ? c.niche === filters.niche : true))
      .filter((c) => (filters.status ? c.status === filters.status : true))
      .filter((c) => (filters.foundBy ? formatFoundBy(c.foundBy) === filters.foundBy : true))
      .filter((c) =>
        q ? c.business.toLowerCase().includes(q) || c.contact.toLowerCase().includes(q) : true,
      ).length;
  }, [all, filters.search, filters.niche, filters.status, filters.foundBy, filters.priority]);

  // Final result set (list + map).
  //  - With an origin: businesses within `radiusKm` of that origin, nearest first.
  //  - Without an origin: EVERY candidate (name-sorted). Never empty just because the data is spread
  //    across regions — the user drills in by choosing a location or sharing their position.
  const clients = useMemo(() => {
    if (!origin) {
      return [...candidates].sort(sortByName);
    }
    const here: LatLng = { lat: origin.lat, lng: origin.lng };
    return candidates
      .map((c) =>
        c.latitude !== null && c.longitude !== null
          ? { ...c, distanceKm: haversineKm(here, { lat: c.latitude, lng: c.longitude }) }
          : c,
      )
      .filter((c) => (c.distanceKm !== undefined ? c.distanceKm <= filters.radiusKm : false))
      .sort(sortByDistanceThenName);
  }, [candidates, origin, filters.radiusKm]);

  const patch = (next: Partial<NearbyFilters>) => setFilters((f) => ({ ...f, ...next }));

  // ── Origin selection (product order of preference) ──────────────────────────────────────────

  /** (1) User-selected target location: center the search on a chosen place. "" clears the origin. */
  const selectLocation = useCallback(
    (area: string) => {
      if (!area) {
        setOrigin((o) => (o?.kind === "location" ? null : o));
        return;
      }
      // Representative point of the chosen location = centre of that location's own businesses. This
      // is the SELECTED place's centroid, not the whole dataset's — origin stays location-driven.
      const pts = all
        .filter((c) => c.area === area && c.latitude !== null && c.longitude !== null)
        .map((c) => ({ lat: c.latitude as number, lng: c.longitude as number }));
      const c = centroid(pts);
      if (c) setOrigin({ lat: c.lat, lng: c.lng, label: area, kind: "location" });
    },
    [all],
  );

  /** (2) Browser geolocation: measure distance from the user's device position, if permitted. */
  const useMyLocation = useCallback(() => {
    // CHECKED BEFORE ASKING, because asking would answer the wrong question. On an insecure origin
    // the browser refuses outright and reports PERMISSION_DENIED — indistinguishable from a user who
    // clicked Block, and the reason this UI kept blaming a permission nobody had touched.
    const blocked = geolocationPreflight(
      typeof navigator === "undefined" ? undefined : navigator,
      typeof window === "undefined" ? undefined : window,
    );
    if (blocked) {
      setGeoStatus(blocked);
      return;
    }

    setGeoStatus("locating");
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setGeoStatus("granted");
        setOrigin({
          // The browser's REAL coordinates. Nothing here substitutes, rounds or defaults them: an
          // origin that is not where the user actually is would silently mis-filter the radius.
          lat: pos.coords.latitude,
          lng: pos.coords.longitude,
          label: "My location",
          kind: "geolocation",
        });
      },
      // The error's own code, mapped one-to-one. READING IT is the whole fix — this callback used
      // to discard its argument and report every failure as a refused permission.
      (error: GeolocationPositionError) => setGeoStatus(geolocationStatusFor(error)),
      { enableHighAccuracy: false, timeout: 10_000, maximumAge: 60_000 },
    );
  }, []);

  /** Clear the origin → return to the "show everything" view. */
  const clearOrigin = useCallback(() => setOrigin(null), []);

  return {
    clients,
    filters,
    patch,
    options,
    origin,
    selectLocation,
    useMyLocation,
    clearOrigin,
    geoStatus,
    totalCount: all.length,
    /** How many rows would show if the priority filter alone were dropped. See `matchesIgnoringPriority`. */
    matchesIgnoringPriority,
    isLoading: query.isLoading,
    isError: query.isError,
  };
}

const STATUS_LABELS: Record<string, string> = {
  new: "New",
  interested: "Interested",
  "awaiting-reply": "Awaiting Reply",
  "call-back-req": "Call Back Requested",
  declined: "Declined",
};

function formatStatus(status: string): string {
  return STATUS_LABELS[status] ?? status;
}

// Derived prospecting-priority labels, most-actionable first (High = weakest web presence).
const PRIORITY_ORDER = ["High", "Medium", "Low"] as const;

/** Readable label for the derived prospecting priority ("high" → "High"). */
function formatPriority(priority: string): string {
  return priority.charAt(0).toUpperCase() + priority.slice(1);
}

// Discovery lanes, baseline first. Like PRIORITY_ORDER this is a CLOSED vocabulary the server
// decides, not a set derived from whatever the pool currently contains.
const FOUND_BY_ORDER = ["OpenStreetMap", "AI-assisted · OpenStreetMap", "Google Places"] as const;

// Server lane value → readable label. EXPLICIT, with no catch-all: a lane this build does not know
// must stay visibly distinct rather than being folded into OpenStreetMap, which would credit the
// deterministic sweep with a lead it never found. "Unknown" is deliberately absent from
// FOUND_BY_ORDER, so such a row is never selectable by lane but still appears under "All sources".
//
// TWO DIMENSIONS IN ONE LABEL, and only where the record carries both. The AI lane's businesses are
// OpenStreetMap nodes reached through AI-proposed searches, so "AI-assisted" alone read as though AI
// were itself the factual source; naming the source keeps this answering "where did the business
// come from".
//
// THERE IS DELIBERATELY NO "AI-assisted · Google Places". A Google lead carries no per-lead record of
// whether AI shaped the search that found it — `foundBy` is derived from `leads.source` alone, and
// `crm_leads` has no strategy or run column. The only AI fact available is the RUN's `ai_outcome`,
// which rises to `applied` on the first AI-planned search and never comes back down: run 85 read
// `applied` while 23% of its targets were AI-planned. Labelling Google leads from it would be wrong
// about roughly three in four. Factual attribution is kept instead of an invented one.
const FOUND_BY_LABELS: Record<string, string> = {
  openstreetmap: "OpenStreetMap",
  "ai-assisted": "AI-assisted · OpenStreetMap",
  "google-places": "Google Places",
};

/** Readable label for a discovery lane ("openstreetmap" → "OpenStreetMap"). */
function formatFoundBy(foundBy: string): string {
  return FOUND_BY_LABELS[foundBy] ?? "Unknown";
}

function distinct(values: (string | null)[]): string[] {
  return Array.from(new Set(values.filter((v): v is string => v !== null && v !== ""))).sort();
}

function sortByName(a: NearbyClient, b: NearbyClient): number {
  return a.business.localeCompare(b.business);
}

/** Nearest first once distances exist; alphabetical by business name until then. */
function sortByDistanceThenName(a: NearbyClient, b: NearbyClient): number {
  if (a.distanceKm !== undefined && b.distanceKm !== undefined) {
    return a.distanceKm - b.distanceKm;
  }
  return a.business.localeCompare(b.business);
}
