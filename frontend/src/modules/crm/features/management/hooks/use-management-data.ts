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
 * Management Dashboard KPI row. Four cards are now LIVE from real backend computations:
 *   - Total Leads      → GET /dashboard/kpis (`totalLeads`)
 *   - Active Leads     → GET /dashboard/performance-summary (`activeLeads`, ACTIVE_SET)
 *   - Converted Leads  → GET /dashboard/performance-summary (`convertedLeads`, deals)
 *   - Conversion Rate  → GET /dashboard/performance-summary (`conversionRate`, converted ÷ engaged)
 *
 * All are ownership-scoped server-side; for Admin/Super-Admin the scope is the whole CRM. No business
 * formula lives in the frontend. **Pending Follow-ups** stays a placeholder ("—") — there is no
 * follow-up/appointment module yet (Planner is a separate workstream), so it is shown as pending, not
 * fabricated.
 */
const BASE_KPIS: ManagementData = {
  range: "Last 6 months",
  kpis: [
    { id: "total-leads", label: "Total Leads", value: "—", subtitle: "All leads in the pipeline", icon: "target", tone: "teal" },
    { id: "active-leads", label: "Active Leads", value: "—", subtitle: "Currently being worked", icon: "zap", tone: "teal" },
    { id: "converted-leads", label: "Converted Leads", value: "—", subtitle: "Won business", icon: "check", tone: "teal" },
    { id: "conversion-rate", label: "Conversion Rate", value: "—", subtitle: "Converted / engaged leads", icon: "trending", tone: "green" },
    // Pending — no follow-up module exists yet (Planner is a separate workstream). Not fabricated.
    { id: "pending-follow-ups", label: "Pending Follow-ups", value: "—", subtitle: "Pending Planner integration", icon: "clock", tone: "amber" },
  ],
};

/**
 * Live Total Leads. Reuses the SAME query as the BD Dashboard KPIs and the Leads-by-Status card
 * (`queryKeys.dashboard.kpis()` + `/dashboard/kpis`) so React Query dedupes to a single request.
 */
function useTotalLeads() {
  const query = useQuery<{ totalLeads: number }>({
    queryKey: queryKeys.dashboard.kpis(),
    queryFn: () => apiClient.get("/dashboard/kpis").then((r) => r.data.data),
  });
  return query.data?.totalLeads;
}

export function useManagementData() {
  const [base] = useState<ManagementData>(BASE_KPIS);
  const [range, setRange] = useState(base.range);

  const totalLeads = useTotalLeads();
  // Reuses GET /dashboard/performance-summary (ownership-scoped; Admin/SA = whole CRM). Shared query
  // key with "My Performance" → no duplicate request, no duplicate computation.
  const { data: summary } = useMyPerformanceSummary();

  // Live value per card id (undefined → keep the base "—" until the query resolves / when pending).
  const liveValue: Record<string, string | undefined> = {
    "total-leads": totalLeads !== undefined ? String(totalLeads) : undefined,
    "active-leads": summary ? String(summary.activeLeads) : undefined,
    "converted-leads": summary ? String(summary.convertedLeads) : undefined,
    "conversion-rate": summary ? formatConversionRate(summary.conversionRate) : undefined,
    // "pending-follow-ups" has no live source (pending) → always keeps its "—" placeholder.
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
