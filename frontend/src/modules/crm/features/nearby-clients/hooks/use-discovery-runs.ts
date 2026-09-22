"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import {
  buildStartRunBody,
  isAiStartRefusal,
} from "@/modules/crm/features/nearby-clients/lib/ai-discovery";
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
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: StartDiscoveryRunInput) =>
      apiClient
        // THE TWO MODES SEND DIFFERENT BODIES, and the nationwide one deliberately sends LESS — see
        // `buildStartRunBody`. `ai_strategy` travels only on an AI Discovery run, so an ordinary
        // run's request is exactly what it was before AI Discovery existed.
        .post("/discovery/runs", buildStartRunBody(input))
        .then((r) => r.data.data as DiscoveryRun),
    onError: (err) => {
      // 409 = an overlapping scope is already running. Nothing broke and nothing was charged, so
      // report it neutrally, the way the qualify-prospect conflict does — not as a red failure.
      toast.error(getApiErrorMessage(err, "Could not start discovery"));

      // AI was refused before anything was spent: the capability this dialog read is stale (credit
      // ran out, or an admin switched AI off). Re-read it so the AI action stops being offered.
      if (isAiStartRefusal(err)) {
        queryClient.invalidateQueries({ queryKey: queryKeys.nearbyClients.discoveryConfig() });
      }
    },
  });
}

/**
 * Stop a run between searches → POST /discovery/runs/{id}/cancel.
 *
 * Honest about its limits, exactly as the endpoint is: it prevents the NEXT provider call, cannot
 * refund one already made, and leaves everything already discovered in the CRM. The poller picks up
 * the `cancelled` status on its next tick; the response is also written straight into its cache so
 * the panel changes the moment the server agrees. 409 means the run had already finished — the poll
 * is refreshed so the panel shows how it actually ended.
 */
export function useCancelDiscoveryRun() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (runId: string) =>
      apiClient
        .post(`/discovery/runs/${runId}/cancel`)
        .then((r) => r.data.data as DiscoveryRun),
    onSuccess: (run) => {
      queryClient.setQueryData(queryKeys.nearbyClients.discoveryRun(run.runId), (prev: DiscoveryRun | undefined) =>
        // The cancel response omits the per-area summary; keep what the poller already had.
        prev === undefined ? run : { ...prev, ...run, areas: run.areas ?? prev.areas },
      );
      queryClient.invalidateQueries({ queryKey: queryKeys.nearbyClients.discoveryRun(run.runId) });
    },
    onError: (err, runId) => {
      toast.error(getApiErrorMessage(err, "Could not cancel the run"));
      queryClient.invalidateQueries({ queryKey: queryKeys.nearbyClients.discoveryRun(runId) });
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

        // An AI run that stopped for lack of credit has just changed the AI capability server-side;
        // re-read it so the AI action is no longer offered.
        if (run.ai?.outcome === "insufficient_credit") {
          queryClient.invalidateQueries({ queryKey: queryKeys.nearbyClients.discoveryConfig() });
        }
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
