"use client";

import { useCallback } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";
import type { ActivityApi } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";
import type { CopilotActivityEntry } from "@/modules/crm/features/copilot/engine/types";

/** A chat reply, not a log page — enough to answer "what happened recently" at a glance. */
const FEED_LIMIT = 5;

/**
 * What has happened recently across the caller's own work — the portfolio-level counterpart to the
 * per-lead `last-contact` answer.
 *
 * THE SAME FEED THE DASHBOARD RENDERS. `GET /activities` is the generic append-only Activity log
 * behind the Dashboard "Recent Activity" card and the Activity Logs page. Visibility is the
 * endpoint's `Activity::scopeVisibleTo`: Admin/SA see the CRM, and a BD sees only rows they caused
 * OR rows whose subject is a lead assigned to them — so a BD can never read another BD's trail. No
 * `user_id` is sent, so this is always the caller's own scope and can never target another BD (that
 * parameter is Admin/SA-only and enforced server-side). `forCrmReporting()` already excludes
 * unqualified Nearby-pool prospects, so the Copilot inherits that rule rather than restating it.
 *
 * SAME KEY, SAME STORED SHAPE. `useActivityFeed` caches the WHOLE envelope (`r.data`) under
 * `queryKeys.dashboard.recentActivity("self")`. This stores the identical shape under the identical
 * key — deliberately, so the two genuinely share one cache entry instead of fighting over it. Storing
 * a bare array here would repeat exactly the defect that had to be removed from the per-lead activity
 * lookup, where two hooks wrote different shapes to one key and whichever ran first broke the other.
 *
 * Imperative, like the other in-turn lookups: the Copilot renders on every page, and a render-time
 * query would fetch this for every turn that never asks for it. On the Dashboard the entry is already
 * warm, so asking there costs no request at all.
 */
export function useCopilotActivityFeed() {
  const queryClient = useQueryClient();

  return useCallback(async (): Promise<CopilotActivityEntry[] | null> => {
    try {
      const envelope = await queryClient.fetchQuery({
        queryKey: queryKeys.dashboard.recentActivity("self"),
        queryFn: () => apiClient.get("/activities").then((r) => r.data as { data: ActivityApi[] }),
      });

      const rows = envelope?.data ?? [];
      // Newest-first from the API (`latest('id')`); keep that order rather than re-sorting.
      return rows.slice(0, FEED_LIMIT).map((row) => ({
        description: row.description || row.event || "activity",
        at: formatActivityTimestamp(row.timestamp),
        leadName: row.subject?.label ?? null,
      }));
    } catch {
      // Null means "I couldn't read it" — never an empty list, which would claim nothing happened.
      return null;
    }
  }, [queryClient]);
}
