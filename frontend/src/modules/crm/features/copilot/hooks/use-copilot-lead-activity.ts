"use client";

import { useCallback } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";
import type { ActivityApi } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";

/**
 * The newest recorded activity on one lead — the honest answer to "kailan ko huling nakausap si X".
 *
 * THE CANONICAL TYPE, NOT A HAND-WRITTEN ONE. Rows are typed as the shared {@link ActivityApi}, the
 * same contract the Dashboard feed, the Activity Logs page and the Lead Details timeline read. The
 * first version of this hook declared its own `{ created_at?: string }` shape and silently never
 * matched: `ActivityResource` emits `timestamp` (from `created_at?->toIso8601String()`) and there is
 * no `created_at` key in the payload at all. Because every field was optional, `tsc` had nothing to
 * object to, so the intent shipped answering "no recorded activity" for every lead. Importing the
 * real type is what makes that class of bug a compile error instead of a silent wrong answer.
 *
 * ITS OWN CACHE KEY. `queryKeys.leads.activities(id)` is already owned by `useLeadActivities`, which
 * stores the WHOLE envelope (`r.data`) and reads `query.data.data`. Writing this hook's bare array
 * under that same key made the two disagree about the cached shape, and whichever wrote first broke
 * the other — the timeline threw on `undefined.map()`, reached through this very intent's navigate.
 * Appending a `"copilot"` segment keeps the entry under the `leads.detail(id)` prefix, so existing
 * lead-detail invalidations still refresh it, while the exact key can never collide again.
 *
 * `GET /leads/{lead}/activities` is the real trail: append-only, causer-stamped, newest-first via the
 * controller's `latest()`, and authorized by the route's `view` policy — so this can only read a lead
 * the caller may already see. The endpoint scopes to `$lead->activities()`, so no other lead's
 * history is reachable through it. Deliberately NOT `leads.last_activity`: that column is a Lead
 * Score input nothing in production stamps, so quoting it would present a stale date as fact.
 *
 * Imperative like the lead lookup, and for the same reason: it runs once per message for a lead known
 * only at send time.
 */
export function useLeadActivityLookup() {
  const queryClient = useQueryClient();

  return useCallback(
    async (leadId: string): Promise<{ description: string; at: string } | null> => {
      try {
        const rows = await queryClient.fetchQuery({
          queryKey: [...queryKeys.leads.activities(leadId), "copilot"] as const,
          queryFn: () =>
            apiClient
              .get(`/leads/${leadId}/activities`)
              .then((r) => (r.data.data ?? []) as ActivityApi[]),
        });

        // Newest-first from the API; take the head rather than re-sorting and risking a different order.
        const newest = rows[0];
        if (!newest?.timestamp) return null;

        return {
          description: newest.description || newest.event || "activity",
          at: formatActivityTimestamp(newest.timestamp),
        };
      } catch {
        // A failed read answers "no recorded activity" rather than breaking the turn — and never
        // implies the lead is inaccessible, which would leak its existence.
        return null;
      }
    },
    [queryClient],
  );
}
