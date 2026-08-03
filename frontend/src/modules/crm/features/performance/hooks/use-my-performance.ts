"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import {
  scopeKey,
  scopeToParams,
  usePerformanceScope,
} from "@/modules/crm/features/performance/lib/performance-scope";

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
  /** Σ leads.amount (Estimated Deal Value, prospect-stage) — NOT revenue; distinct backend field. */
  estimatedPipelineValue: number;
  conversionRate: number | null;
  /** Follow-up Planner events completed this calendar month (real `completed_at`, ownership-scoped). */
  completedFollowUps: number;
  /** Monthly converted-leads goal from Goal Management, or null when none is configured. */
  personalTarget: { value: number; progress: number } | null;
  /** Overdue-and-not-completed Planner events by type (the PO-frozen "missed" rule), ownership-scoped. */
  followUpDiscipline: {
    missedFollowUps: number;
    missedMeetings: number;
    missedCalls: number;
  };
};

/**
 * The performance summary for the CURRENT scope (from {@link usePerformanceScope}). With no provider —
 * My Performance — the scope is `self`, so no param is sent and the endpoint returns the acting BD's own
 * portfolio (unchanged). Reports & Performance wraps its widgets in a scope provider, so the same hook
 * fetches the All-Reports aggregate or a specific BD via `?user_id`, cached separately per scope.
 */
export function useMyPerformanceSummary() {
  const scope = usePerformanceScope();
  return useQuery<PerformanceSummary>({
    queryKey: queryKeys.performance.summary(scopeKey(scope)),
    queryFn: () =>
      apiClient
        .get("/dashboard/performance-summary", { params: scopeToParams(scope) })
        .then((r) => r.data.data),
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
