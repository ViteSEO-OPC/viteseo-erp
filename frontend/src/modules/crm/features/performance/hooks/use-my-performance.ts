"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { STALE } from "@/modules/crm/lib/query-freshness";
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
 *
 * Shell-level: the sidebar's lead badge calls `useDashboardKpis`, which calls this for the
 * Conversion Rate card, so the `self` scope is mounted on every CRM screen — see {@link STALE}.
 * Every write that moves these figures invalidates `queryKeys.performance.all`, and invalidation
 * ignores `staleTime`, so the window never holds a stale number past a change.
 */
export function useMyPerformanceSummary() {
  const scope = usePerformanceScope();
  return useQuery<PerformanceSummary>({
    queryKey: queryKeys.performance.summary(scopeKey(scope)),
    queryFn: () =>
      apiClient
        .get("/dashboard/performance-summary", { params: scopeToParams(scope) })
        .then((r) => r.data.data),
    staleTime: STALE.standard,
  });
}

/**
 * ₱ in compact notation — "₱793K", "₱1.5M", "₱1.98M"; "—" when the value is unavailable.
 *
 * The unit is chosen from the ROUNDED figure, not the raw one, which is what stops the two ugly
 * cases: 1977123 reads "₱1.98M" rather than "₱1977K" (thousands never run past three digits), and
 * 999999 reads "₱1M" rather than "₱1000K" — rounding it to a thousand thousands promotes it to the
 * next unit instead of printing a value that has outgrown its own suffix.
 *
 * Millions carry up to 2 decimals with trailing zeros dropped by Number→string ("₱1M", "₱1.5M",
 * "₱1.98M"). Display only: the raw value is untouched and every KPI computation is server-side.
 */
export function formatPeso(value: number | undefined): string {
  if (value === undefined) return "—";

  // Below a thousand there is nothing to abbreviate.
  if (Math.abs(value) < 1000) return `₱${Math.round(value)}`;

  const thousands = Math.round(value / 1000);
  if (Math.abs(thousands) < 1000) return `₱${thousands}K`;

  // Two decimals, via round-then-scale so 1.977… lands on 1.98 rather than truncating to 1.97.
  const millions = Math.round(value / 10_000) / 100;

  return `₱${millions}M`;
}

/** Conversion rate for display: "28.6%" or "—" when undefined (no engaged leads). */
export function formatConversionRate(rate: number | null | undefined): string {
  if (rate === null || rate === undefined) return "—";
  return `${rate.toFixed(1)}%`;
}
