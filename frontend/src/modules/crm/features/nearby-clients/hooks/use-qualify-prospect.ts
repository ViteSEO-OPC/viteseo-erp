"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
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
      toast.error(getApiErrorMessage(err, "Failed to qualify prospect"));
    },
  });
}
