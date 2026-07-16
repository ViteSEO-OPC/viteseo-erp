"use client";

import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { centroid, haversineKm, type LatLng } from "@/modules/crm/features/nearby-clients/lib/distance";
import type { NearbyClient } from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";

export type NearbyFilters = {
  search: string;
  distanceKm: number;
  area: string; // "" = all
  niche: string; // "" = all
  status: string; // "" = all
};

const INITIAL: NearbyFilters = { search: "", distanceKm: 5, area: "", niche: "", status: "" };

/**
 * Nearby clients (discovered businesses) + filter state. The returned `clients` drive BOTH the list
 * and the map markers, so the two are always in sync.
 *
 * **Distance origin is stable, not the live map center.** Distance is measured from the centre of the
 * text/category-filtered result set — recomputed only when those filters change, never while the user
 * pans the map. This guarantees panning can never empty the result set (an earlier bug where panning
 * beyond the slider radius silently dropped every business from both the list and the map).
 */
export function useNearbyClients() {
  const [filters, setFilters] = useState<NearbyFilters>(INITIAL);

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
      areas: distinct(all.map((c) => c.area)),
      niches: distinct(all.map((c) => c.niche)),
      statuses: distinct(all.map((c) => c.status)),
    }),
    [all],
  );

  // Candidate set — matches the text/category filters but NOT the distance slider. It defines the
  // distance origin, so the origin (and therefore the whole result set) is independent of the slider.
  const candidates = useMemo(() => {
    const q = filters.search.trim().toLowerCase();
    return all
      .filter((c) => (filters.area ? c.area === filters.area : true))
      .filter((c) => (filters.niche ? c.niche === filters.niche : true))
      .filter((c) => (filters.status ? c.status === filters.status : true))
      .filter((c) =>
        q ? c.business.toLowerCase().includes(q) || c.contact.toLowerCase().includes(q) : true,
      );
  }, [all, filters.search, filters.area, filters.niche, filters.status]);

  // Stable origin: centre of the candidate set. Changes only when the filters change — never on pan.
  const origin = useMemo<LatLng | null>(
    () =>
      centroid(
        candidates
          .filter((c) => c.latitude !== null && c.longitude !== null)
          .map((c) => ({ lat: c.latitude as number, lng: c.longitude as number })),
      ),
    [candidates],
  );

  const withDistance = useMemo(
    () =>
      candidates.map((c) =>
        origin && c.latitude !== null && c.longitude !== null
          ? { ...c, distanceKm: haversineKm(origin, { lat: c.latitude, lng: c.longitude }) }
          : c,
      ),
    [candidates, origin],
  );

  // Final result set (list + map): the candidates within the slider radius of the stable origin.
  const clients = useMemo(
    () =>
      withDistance
        .filter((c) => (c.distanceKm !== undefined ? c.distanceKm <= filters.distanceKm : true))
        .sort(sortByDistanceThenName),
    [withDistance, filters.distanceKm],
  );

  const patch = (next: Partial<NearbyFilters>) => setFilters((f) => ({ ...f, ...next }));

  return {
    clients,
    filters,
    patch,
    options,
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

function distinct(values: (string | null)[]): string[] {
  return Array.from(new Set(values.filter((v): v is string => v !== null && v !== ""))).sort();
}

/** Nearest first once distances exist; alphabetical by business name until then. */
function sortByDistanceThenName(a: NearbyClient, b: NearbyClient): number {
  if (a.distanceKm !== undefined && b.distanceKm !== undefined) {
    return a.distanceKm - b.distanceKm;
  }
  return a.business.localeCompare(b.business);
}
