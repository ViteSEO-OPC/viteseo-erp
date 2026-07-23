"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";

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
      toast.error(getApiErrorMessage(err, "Failed to mark lead as won"));
    },
  });
}

/**
 * Mark a lead as Lost → POST /leads/{id}/lost. The backend logs a `lead.lost` Activity and completes
 * any open follow-ups. Creates NO Deal and does not affect Revenue, Conversion, or the Lead Score.
 * Refreshes the lead detail (timeline) and the shared Planner cache (completed follow-ups).
 */
export function useMarkLost() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason?: string | null }) =>
      apiClient.post(`/leads/${id}/lost`, reason ? { reason } : {}).then((r) => r.data.data),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.detail(id) }); // score + timeline
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
      queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.planner.all }); // completed follow-ups
      toast.success("Lead marked as lost");
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to mark lead as lost"));
    },
  });
}
