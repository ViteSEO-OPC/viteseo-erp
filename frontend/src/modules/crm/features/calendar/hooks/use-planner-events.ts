"use client";

import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { STALE } from "@/modules/crm/lib/query-freshness";
import type { PlannerEvent } from "@/modules/crm/features/calendar/types/calendar-types";

/**
 * The single Planner data source. Fetches every PlannerEvent visible to the current BD (the API
 * scopes to their own via PlannerEvent::scopeVisibleTo). **Every** Planner view — Month, Week,
 * Day, Year, Agenda, and the Board — reads from this one query, so all views are presentations of
 * the same dataset. A mutation invalidating `queryKeys.planner.list()` refreshes them together
 * (cross-view synchronization). The Board introduces no separate state or endpoint.
 *
 * Shell-level: the sidebar's Outreach and Planner badges and the Copilot's schedule context both
 * read this query, so it is mounted on every CRM screen — see {@link STALE}.
 */
export function usePlannerEvents(): UseQueryResult<PlannerEvent[]> {
  return useQuery<PlannerEvent[]>({
    queryKey: queryKeys.planner.list(),
    queryFn: () =>
      apiClient.get("/planner-events").then((r) => r.data.data as PlannerEvent[]),
    staleTime: STALE.standard,
  });
}
