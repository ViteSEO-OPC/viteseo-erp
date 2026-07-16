"use client";

import { useCallback, useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { centroid, haversineKm, type LatLng } from "@/modules/crm/features/nearby-clients/lib/distance";
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
};

/** Radius options (km), measured from the selected origin. */
export const RADIUS_OPTIONS = [5, 10, 25, 50] as const;

const INITIAL: NearbyFilters = { search: "", radiusKm: 10, niche: "", status: "", priority: "" };

export type GeolocationStatus = "idle" | "locating" | "granted" | "denied" | "unavailable";

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
  const all = useMemo(
    () => (query.data ?? []).map((c) => ({ ...c, status: formatStatus(c.status) })),
    [query.data],
  );

  const options = useMemo(
    () => ({
      // Selectable target locations (the discovered admin areas) — each becomes a search origin.
      locations: distinct(all.map((c) => c.area)),
      niches: distinct(all.map((c) => c.niche)),
      statuses: distinct(all.map((c) => c.status)),
      // Derived prospecting priorities present in the pool, ranked High → Medium → Low.
      priorities: PRIORITY_ORDER.filter((label) =>
        all.some((c) => formatPriority(c.priority) === label),
      ),
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
      .filter((c) =>
        q ? c.business.toLowerCase().includes(q) || c.contact.toLowerCase().includes(q) : true,
      );
  }, [all, filters.search, filters.niche, filters.status, filters.priority]);

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
    if (typeof navigator === "undefined" || !navigator.geolocation) {
      setGeoStatus("unavailable");
      return;
    }
    setGeoStatus("locating");
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setGeoStatus("granted");
        setOrigin({
          lat: pos.coords.latitude,
          lng: pos.coords.longitude,
          label: "My location",
          kind: "geolocation",
        });
      },
      () => setGeoStatus("denied"),
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
