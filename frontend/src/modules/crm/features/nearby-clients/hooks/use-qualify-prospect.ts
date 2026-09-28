"use client";

import { isAxiosError } from "axios";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/**
 * Qualify a discovered prospect into the CRM → POST /leads/{id}/qualify.
 *
 * This is the bridge from the Prospect Pool (Nearby Clients) to Active CRM Leads. The backend stamps
 * the lifecycle (`qualified_at`/`qualified_by`), assigns ownership to the acting BD, and computes the
 * Lead Score for the first time. From this point the business participates in Business Computation.
 *
 * On success the prospect leaves the Nearby pool and appears in Leads, so we invalidate the Nearby
 * list plus every CRM-computation surface (leads, dashboard, performance). No client-side computation
 * — the frontend only refreshes caches.
 */
export function useQualifyProspect() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => apiClient.post(`/leads/${id}/qualify`).then((r) => r.data.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.nearbyClients.all }); // leaves the pool
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.all }); // enters the Leads list
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all }); // counts / KPIs / pipeline
      queryClient.invalidateQueries({ queryKey: queryKeys.performance.all }); // My Performance
      toast.success("Prospect qualified — added to your CRM leads");
    },
    onError: (err) => {
      // 409 = another BD won the claim while this client's list was stale. Nothing broke and nothing
      // was created, so report it the way the duplicate-Deal guard does (see use-lead-outcome) —
      // neutral information, not a red failure. The backend sentence names the winner.
      //
      // Refetching the pool is the point. The board keeps claimed prospects listed, so the row comes
      // back marked "Already in CRM" with its Accept disabled; without this it survives on a stale
      // cache and the BD can press a live button repeatedly, getting the same 409 each time.
      if (isAxiosError(err) && err.response?.status === 409) {
        queryClient.invalidateQueries({ queryKey: queryKeys.nearbyClients.all });
        toast.info(getApiErrorMessage(err, "This prospect has already been added to the CRM."));
        return;
      }
      toast.error(getApiErrorMessage(err, "Failed to qualify prospect"));
    },
  });
}
