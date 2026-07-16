"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { activityIcon, type ActivityApi } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";
import type { TimelineEvent } from "@/modules/crm/features/leads/types/lead-detail-types";

/**
 * Live Activity Timeline for a single lead. Reuses the EXISTING per-lead endpoint
 * `GET /leads/{lead}/activities` (ownership-enforced by the route's `view` policy; newest-first
 * via the controller's `latest()`), the EXISTING `Activity` model/`ActivityResource` shape, and
 * the shared event→icon mapping (`activityIcon`). It shows ONLY this lead's activities — the
 * endpoint scopes to `$lead->activities()`, so login/logout (subject-null) and other leads'
 * activities are structurally excluded, no client-side filtering added.
 *
 * Keyed under `leads.detail(id)` so the existing update/assign invalidations of that detail key
 * also refresh the timeline (React Query prefix match) — new lead activity appears after refetch.
 */
export function useLeadActivities(id: string) {
  const query = useQuery<{ data: ActivityApi[] }>({
    queryKey: queryKeys.leads.activities(id),
    queryFn: () => apiClient.get(`/leads/${id}/activities`).then((r) => r.data),
    enabled: !!id,
  });

  // The endpoint returns activities newest-first already; keep that order. Each row shows the
  // stored `description`, its timestamp, and the shared icon/color for its event type.
  const data: TimelineEvent[] | undefined = query.data?.data.map((api) => ({
    id: api.id,
    description: api.description,
    timestamp: api.timestamp,
    type: activityIcon(api.event),
  }));

  return { data, isLoading: query.isLoading, isError: query.isError };
}
