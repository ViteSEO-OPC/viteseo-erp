"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import {
  useMyPerformanceSummary,
  formatConversionRate,
} from "@/modules/crm/features/performance/hooks/use-my-performance";
import type { ManagementData } from "@/modules/crm/features/management/types/management-types";

/**
 * Management Dashboard KPI row. All five cards are LIVE from real backend computations:
 *   - Total Leads         → GET /dashboard/kpis (`totalLeads`)
 *   - Active Leads        → GET /dashboard/performance-summary (`activeLeads`, ACTIVE_SET)
 *   - Converted Leads     → GET /dashboard/performance-summary (`convertedLeads`, deals)
 *   - Conversion Rate     → GET /dashboard/performance-summary (`conversionRate`, converted ÷ engaged)
 *   - Pending Follow-ups  → GET /dashboard/kpis (`followUpsDue`)
 *
 * All are ownership-scoped server-side; for Admin/Super-Admin the scope is the whole CRM. No business
 * formula lives in the frontend.
 *
 * Pending Follow-ups was a placeholder ("—") on the grounds that no follow-up module existed yet.
 * Planner shipped, and `followUpsDue` is the SAME canonical count the BD dashboard KPI and the
 * Reports "Pending follow-ups" card already read — so the tile now shows it rather than an em dash
 * next to a number the CRM has been computing all along. Deliberately NOT a second definition: it
 * rides `DashboardController::pendingFollowUps()` like every other consumer.
 */
const BASE_KPIS: ManagementData = {
  range: "Last 6 months",
  kpis: [
    { id: "total-leads", label: "Total Leads", value: "—", subtitle: "All leads in the pipeline", icon: "target", tone: "teal" },
    { id: "active-leads", label: "Active Leads", value: "—", subtitle: "Currently being worked", icon: "zap", tone: "teal" },
    { id: "converted-leads", label: "Converted Leads", value: "—", subtitle: "Won business", icon: "check", tone: "teal" },
    { id: "conversion-rate", label: "Conversion Rate", value: "—", subtitle: "Converted / engaged leads", icon: "trending", tone: "green" },
    // Amber is kept: overdue/today/upcoming work is the one tile in this row that asks for action.
    { id: "pending-follow-ups", label: "Pending Follow-ups", value: "—", subtitle: "Overdue, today and upcoming", icon: "clock", tone: "amber" },
  ],
};

/**
 * The dashboard KPI payload. Reuses the SAME query as the BD Dashboard KPIs and the Leads-by-Status
 * card (`queryKeys.dashboard.kpis()` + `/dashboard/kpis`) so React Query dedupes to a single request.
 *
 * Returns the payload rather than one field: Total Leads and Pending Follow-ups both come from this
 * one response, and reading them through a second hook on the same key would only be another way to
 * spell the same fetch.
 */
function useDashboardKpis() {
  const query = useQuery<{ totalLeads: number; followUpsDue: number }>({
    queryKey: queryKeys.dashboard.kpis(),
    queryFn: () => apiClient.get("/dashboard/kpis").then((r) => r.data.data),
  });
  return query.data;
}

export function useManagementData() {
  const [base] = useState<ManagementData>(BASE_KPIS);
  const [range, setRange] = useState(base.range);

  const kpis = useDashboardKpis();
  // Reuses GET /dashboard/performance-summary (ownership-scoped; Admin/SA = whole CRM). Shared query
  // key with "My Performance" → no duplicate request, no duplicate computation.
  const { data: summary } = useMyPerformanceSummary();

  // Live value per card id (undefined → keep the base "—" until the query resolves / when pending).
  const liveValue: Record<string, string | undefined> = {
    "total-leads": kpis ? String(kpis.totalLeads) : undefined,
    "active-leads": summary ? String(summary.activeLeads) : undefined,
    "converted-leads": summary ? String(summary.convertedLeads) : undefined,
    "conversion-rate": summary ? formatConversionRate(summary.conversionRate) : undefined,
    // 0 is a real measurement here — an empty queue — so it must render as "0", never as the "—"
    // the base tile carries for "not loaded yet".
    "pending-follow-ups": kpis ? String(kpis.followUpsDue) : undefined,
  };

  const data: ManagementData = {
    ...base,
    kpis: base.kpis.map((kpi) => {
      const value = liveValue[kpi.id];
      return value !== undefined ? { ...kpi, value } : kpi;
    }),
  };

  return { data, range, setRange };
}
