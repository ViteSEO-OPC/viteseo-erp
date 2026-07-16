"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { BdPerformanceRow } from "@/modules/crm/features/management/types/management-types";

/**
 * Live "BD Performance Overview" data (Admin/SA Management Dashboard). One row per active
 * Business Development Manager, aggregated server-side by `GET /dashboard/bd-performance`
 * (no client-side aggregation, no duplicated logic). Every active BD is returned automatically,
 * so a newly-created BD account appears with no frontend change.
 *
 * Reuses the shared React Query cache under the `dashboard` query-key namespace, matching the
 * other dashboard hooks. The backend returns only the one legitimately-sourced metric
 * (`assigned`); follow-ups / converted / rate / status stay display-only placeholders in the card.
 */
export function useBdPerformance() {
  const query = useQuery<BdPerformanceRow[]>({
    queryKey: queryKeys.dashboard.bdPerformance(),
    queryFn: () => apiClient.get("/dashboard/bd-performance").then((r) => r.data.data),
  });

  return { data: query.data, isLoading: query.isLoading, isError: query.isError };
}
