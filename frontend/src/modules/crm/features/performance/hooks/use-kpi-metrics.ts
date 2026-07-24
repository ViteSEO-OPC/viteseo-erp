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
 * One operational KPI from the BD KPI Engine catalog (M-01…M-06), exactly as the backend computes it.
 * `value` is null when the metric is not evaluable (e.g. a zero-denominator ratio); the frontend never
 * recomputes it — it only reads/formats. See `GET /dashboard/kpi-metrics`.
 */
export type KpiMetric = {
  id: string;
  label: string;
  unit: "count" | "percent" | "breakdown";
  value: number | null;
  evaluable: boolean;
  reason: string | null;
  components: Record<string, number> | null;
  feeds: string | null;
};

/**
 * The ownership-scoped BD KPI catalog from the KPI Engine — the single authoritative owner of the
 * operational metrics (e.g. M-04 Engagement Coverage). Consumers read a metric's server-computed value;
 * they must not re-derive any formula in the frontend.
 */
export function useKpiMetrics() {
  const scope = usePerformanceScope();
  return useQuery<KpiMetric[]>({
    queryKey: queryKeys.dashboard.kpiMetrics(scopeKey(scope)),
    queryFn: () =>
      apiClient
        .get("/dashboard/kpi-metrics", { params: scopeToParams(scope) })
        .then((r) => r.data.data.metrics as KpiMetric[]),
  });
}

/** Find a metric by catalog id (e.g. "M-04"). */
export function findMetric(
  metrics: KpiMetric[] | undefined,
  id: string,
): KpiMetric | undefined {
  return metrics?.find((m) => m.id === id);
}
