"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/**
 * Live "My Performance" personal KPI summary — the ownership-scoped `GET /dashboard/performance-
 * summary` (a BD sees only their own portfolio). Every value is computed **server-side**; the
 * frontend only formats and lays them out — no client-side business formula.
 *
 * - assignedLeads / activeLeads / engagedLeads / convertedLeads : counts
 * - overallRevenue : Σ deal amounts (pesos)
 * - conversionRate : converted ÷ engaged × 100 (1 dp), or `null` when there are no engaged leads
 *   (undefined — the UI shows "—", never a fabricated 0%)
 */
export type PerformanceSummary = {
  assignedLeads: number;
  activeLeads: number;
  engagedLeads: number;
  convertedLeads: number;
  overallRevenue: number;
  conversionRate: number | null;
};

export function useMyPerformanceSummary() {
  return useQuery<PerformanceSummary>({
    queryKey: queryKeys.performance.summary(),
    queryFn: () =>
      apiClient.get("/dashboard/performance-summary").then((r) => r.data.data),
  });
}

/** ₱ with thousands abbreviated (e.g. 160000 → "₱160K"); "—" when the value is unavailable. */
export function formatPeso(value: number | undefined): string {
  if (value === undefined) return "—";
  if (value >= 1000) return `₱${Math.round(value / 1000)}K`;
  return `₱${Math.round(value)}`;
}

/** Conversion rate for display: "28.6%" or "—" when undefined (no engaged leads). */
export function formatConversionRate(rate: number | null | undefined): string {
  if (rate === null || rate === undefined) return "—";
  return `${rate.toFixed(1)}%`;
}
