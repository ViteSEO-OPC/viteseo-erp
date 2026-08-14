"use client";

import { useQuery, type UseQueryResult } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import {
  scopeKey,
  type PerformanceScope,
} from "@/modules/crm/features/performance/lib/performance-scope";
import type { PlannerEvent } from "@/modules/crm/features/calendar/types/calendar-types";

/**
 * Planner History — completed work with no time limit, from the SAME `/planner-events` endpoint the
 * live Planner uses (`?view=history`). Not a second Planner: same route, same ownership scope, same
 * policy — a different question asked of the same rows.
 *
 * Deliberately a separate query from {@link usePlannerEvents}, not a parameterised version of it.
 * The live Planner's one shared list cache is written OPTIMISTICALLY by the Board's drag mutation
 * (setQueryData against that exact key); keying it per scope would leave those writes patching only
 * the default entry. History is read-only, so it can safely own a per-scope cache instead.
 *
 * Ownership is enforced server-side. A BD's History is their own completed work — passing another
 * BD's id simply intersects with their own rows and returns nothing, because `assigned_to` is
 * applied after PlannerEvent::scopeVisibleTo. The scope below is therefore a FILTER, never a grant.
 */
export function usePlannerHistory(
  scope: PerformanceScope,
  enabled = true,
): UseQueryResult<PlannerEvent[]> {
  return useQuery<PlannerEvent[]>({
    queryKey: queryKeys.planner.history(scopeKey(scope)),
    enabled,
    queryFn: () =>
      apiClient
        .get("/planner-events", {
          params: {
            view: "history",
            ...(scope.mode === "bd" ? { assigned_to: scope.userId } : {}),
          },
        })
        .then((r) => r.data.data as PlannerEvent[]),
  });
}
