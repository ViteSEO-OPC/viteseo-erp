"use client";

import { useCallback } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/**
 * The CRM's canonical count of OVERDUE follow-up work.
 *
 * WHY THIS AND NOT `ctx.schedule.overdue`. The Copilot's schedule buckets are cut from
 * {@link isActivePlannerEvent} — the Planner sidebar badge's set, which deliberately INCLUDES `task`
 * and `consultation` ("an overdue task is still something the board is asking the BD to do"). The
 * CRM's follow-up KPIs use a narrower set: `DashboardController::pendingFollowUps()` restricted to
 * `PlannerEvent::FOLLOW_UP_WORK_TYPES` (follow-up / email / call / meeting). Both are real, named,
 * deliberate definitions and `planner-active.ts` states they "must not be collapsed" — so a question
 * that says "follow-up" must be answered from the follow-up set, and a question that says "overdue
 * items" from the badge set. Answering both from one set is what made two adjacent surfaces disagree.
 *
 * NO NEW DEFINITION AND NO NEW ENDPOINT. `pendingFollowUps.counts.overdue` is computed server-side by
 * the same half-open Manila windows behind the Reports "Pending Follow-ups" card, and this reads it
 * through the SAME `GET /dashboard/reports-analytics` and the SAME `queryKeys.reports.analytics`
 * entry that `useReportsAnalytics` already uses — so on the Reports page it is served from cache.
 * Ownership is the endpoint's (`$scope->subject` → `visibleTo`): a BD is counted on their own work,
 * Admin/SA across the CRM. `"self"` is the default scope, i.e. no `user_id`, so this can never read
 * another BD's figures.
 *
 * IMPERATIVE, like the lead and activity lookups. The Reports page is management-only, so a BD's
 * cache is never warm for it — a render-time query would add a request on every page for a question
 * that is rarely asked. Fetching inside the turn costs nothing until someone actually asks.
 */
export function useOverdueFollowUpLookup() {
  const queryClient = useQueryClient();

  return useCallback(async (): Promise<number | null> => {
    try {
      const counts = await queryClient.fetchQuery({
        queryKey: queryKeys.reports.analytics("self"),
        queryFn: () =>
          apiClient.get("/dashboard/reports-analytics").then((r) => r.data.data),
      });

      const overdue = counts?.pendingFollowUps?.counts?.overdue;
      return typeof overdue === "number" ? overdue : null;
    } catch {
      // Say "I couldn't read it" rather than quote a zero that would read as "you're clear".
      return null;
    }
  }, [queryClient]);
}
