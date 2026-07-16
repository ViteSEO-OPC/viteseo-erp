"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type {
  AcquisitionPoint,
  AcquisitionSummary,
} from "@/modules/crm/features/management/types/management-types";

/**
 * Live "Lead Acquisition" data (Admin/SA Management Dashboard). New leads per calendar month over
 * the last 6 months, aggregated server-side by `GET /dashboard/lead-acquisition` (one raw COUNT per
 * month bucket on `leads.created_at` — no client-side aggregation). Every newly created or imported
 * lead appears in its month bucket automatically. Reuses the shared `dashboard` query-key namespace.
 *
 * Descriptive only: the per-month counts feed the chart, plus a 6-month total, monthly average, and
 * the current calendar month's count. No trend / growth % / forecast is computed anywhere.
 */
type LeadAcquisitionApi = {
  points: { month: string; count: number }[];
  totalNew: number;
  monthlyAverage: number;
  currentMonthCount: number;
};

export function useLeadAcquisition() {
  const query = useQuery<LeadAcquisitionApi>({
    queryKey: queryKeys.dashboard.leadAcquisition(),
    queryFn: () =>
      apiClient.get("/dashboard/lead-acquisition").then((r) => r.data.data),
  });

  const api = query.data;

  const points: AcquisitionPoint[] | undefined = api?.points.map((p, i) => ({
    month: p.month,
    value: p.count,
    // The current month is the last bucket; highlight is presentation-only (not a trend signal).
    highlight: i === api.points.length - 1,
  }));

  const summary: AcquisitionSummary | undefined = api
    ? {
        totalNew: api.totalNew,
        monthlyAverage: api.monthlyAverage,
        currentMonthCount: api.currentMonthCount,
      }
    : undefined;

  return { points, summary, isLoading: query.isLoading, isError: query.isError };
}
