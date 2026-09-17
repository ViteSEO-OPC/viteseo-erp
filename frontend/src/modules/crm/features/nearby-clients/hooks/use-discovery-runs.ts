"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import {
  isRunTerminal,
  type DiscoveryRun,
  type StartDiscoveryRunInput,
} from "@/modules/crm/features/nearby-clients/types/discovery-run-types";

/** How often a live run is polled. Matches the existing import-job poller. */
const POLL_MS = 2000;

/**
 * Start an Advanced Lead Discovery run → POST /discovery/runs.
 *
 * ADDITIVE. Nothing else in Nearby Clients calls this: the pool query, every filter and both maps
 * are untouched and still start no discovery. A run begins only when a user presses the button this
 * mutation is wired to.
 *
 * The request returns immediately with a run id (202) — the work happens on the `discovery` queue,
 * because a multi-search sweep cannot be held open by an HTTP connection. Progress arrives through
 * {@link useDiscoveryRun}.
 *
 * NO CACHE IS INVALIDATED HERE. Starting a run discovers nothing yet, so refreshing the board on
 * dispatch would be a lie; the refresh happens when the run reaches a terminal state.
 */
export function useStartDiscoveryRun() {
  return useMutation({
    mutationFn: (input: StartDiscoveryRunInput) =>
      apiClient
        .post(
          "/discovery/runs",
          // THE TWO MODES SEND DIFFERENT BODIES, and the nationwide one deliberately sends LESS. No
          // locations, because coverage comes from the national polygon rather than anything a user
          // typed; and no `limit_per_task`, because the per-cell limit belongs to server
          // configuration — sending the scoped form's 20 here would silently make a nationwide run
          // inherit a number that has nothing to do with it. The backend rejects `locations` on a
          // nationwide request outright, so the two shapes cannot blur.
          input.scope === "nationwide"
            ? {
                scope: "nationwide",
                country: input.country,
                niches: input.niches,
                idempotency_key: input.idempotencyKey,
              }
            : {
                scope: "scoped",
                country: input.country,
                locations: input.locations,
                niches: input.niches,
                limit_per_task: input.limitPerTask,
                idempotency_key: input.idempotencyKey,
              },
        )
        .then((r) => r.data.data as DiscoveryRun),
    onError: (err) => {
      // 409 = an overlapping scope is already running. Nothing broke and nothing was charged, so
      // report it neutrally, the way the qualify-prospect conflict does — not as a red failure.
      toast.error(getApiErrorMessage(err, "Could not start discovery"));
    },
  });
}

/**
 * Poll one run until it finishes → GET /discovery/runs/{id}.
 *
 * Follows the repository's single precedent for a status poll ({@link useImportJobStatus}): enabled
 * only while an id exists, and **polling stops by returning `false` from `refetchInterval` once the
 * status is terminal**, so a finished run issues no further requests.
 *
 * When the run ends, the Nearby Clients pool is invalidated exactly once so newly ingested
 * businesses appear without a page reload. That invalidation is the ONLY way this feature touches
 * the frozen pool — it refreshes the existing query, it does not change it.
 */
export function useDiscoveryRun(runId: string | null) {
  const queryClient = useQueryClient();

  return useQuery({
    queryKey: queryKeys.nearbyClients.discoveryRun(runId ?? ""),
    enabled: Boolean(runId),
    refetchInterval: (query) => (isRunTerminal(query.state.data?.status) ? false : POLL_MS),
    queryFn: async () => {
      const res = await apiClient.get(`/discovery/runs/${runId}`);
      const run = res.data.data as DiscoveryRun;

      if (isRunTerminal(run.status)) {
        // The pool is the destination: a finished run may have added prospects to it.
        queryClient.invalidateQueries({ queryKey: queryKeys.nearbyClients.list() });
      }

      return run;
    },
  });
}

/**
 * Runs still in flight → GET /discovery/runs.
 *
 * This is what lets a browser refresh re-attach to a run instead of orphaning it, and what stops a
 * user starting a second sweep because the first became invisible. Polled on the same cadence while
 * anything is active.
 */
export function useActiveDiscoveryRuns(enabled: boolean) {
  return useQuery({
    queryKey: queryKeys.nearbyClients.discoveryRuns(),
    enabled,
    refetchInterval: (query) => ((query.state.data?.length ?? 0) > 0 ? POLL_MS : false),
    queryFn: async () => {
      const res = await apiClient.get("/discovery/runs");
      return res.data.data as DiscoveryRun[];
    },
  });
}
