"use client";

import { isAxiosError } from "axios";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/**
 * Won is guarded server-side — one active Deal per lead, the financial invariant behind Revenue
 * (Σ deals.amount) and Conversion — so a repeat Won is rejected with 409 and creates nothing.
 * WonConfirmDialog short-circuits on a lead it already knows is won; this constant keeps that
 * message and the 409 backstop below (stale cache, second tab, direct API) reading identically.
 */
export const ALREADY_WON_MESSAGE = "Lead is already marked as Won.";

/**
 * Mark a lead as Won → POST /leads/{id}/won. The backend creates a Deal (Deal bounded context)
 * and logs a `lead.won` Activity; Revenue and Conversion (both sourced only from Deals) update
 * server-side. Refreshes the lead detail + timeline and the Revenue-bearing surfaces (Dashboard,
 * My Performance). No frontend business computation — the client only invalidates caches.
 */
export function useMarkWon() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, amount }: { id: string; amount?: number | null }) =>
      apiClient.post(`/leads/${id}/won`, amount != null ? { amount } : {}).then((r) => r.data.data),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.detail(id) }); // detail + timeline
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
      queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all }); // Revenue / Conversion
      queryClient.invalidateQueries({ queryKey: queryKeys.performance.all }); // My Performance revenue
      toast.success("Lead marked as won");
    },
    onError: (err) => {
      // 409 = the duplicate-Deal guard, not a failure: nothing was created and nothing is wrong.
      // Report it as neutral information rather than the raw server sentence in a red error toast.
      if (isAxiosError(err) && err.response?.status === 409) {
        toast.info(ALREADY_WON_MESSAGE);
        return;
      }
      toast.error(getApiErrorMessage(err, "Failed to mark lead as won"));
    },
  });
}

/**
 * Mark a lead as Lost → POST /leads/{id}/lost. The backend logs a `lead.lost` Activity and completes
 * any open follow-ups. Creates NO Deal and does not affect Revenue, Conversion, or the Lead Score.
 * Refreshes the lead detail (timeline) and the shared Planner cache (completed follow-ups).
 *
 * `alreadyLost` only picks the confirmation copy. Lost is an append-only audit activity with no
 * duplicate guard by design (KB: Lead-Quick-Actions-Architecture §5 — the outcomes are deliberately
 * asymmetric), so a repeat is still SUBMITTED and still recorded; the toast just stops claiming a
 * state change that did not happen.
 */
export function useMarkLost() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason?: string | null; alreadyLost?: boolean }) =>
      apiClient.post(`/leads/${id}/lost`, reason ? { reason } : {}).then((r) => r.data.data),
    onSuccess: (_data, { id, alreadyLost }) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.detail(id) }); // score + timeline
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
      queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.planner.all }); // completed follow-ups
      // Say both true things: the outcome did not change, and the new loss WAS written to the
      // timeline. A green "Lead marked as lost" would misreport a repeat as a fresh transition.
      if (alreadyLost) {
        toast.info("Lead is already marked as Lost.", "A new loss activity was still recorded.");
      } else {
        toast.success("Lead marked as lost");
      }
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to mark lead as lost"));
    },
  });
}
