"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { WebsiteEvidence } from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";

/**
 * The website-evidence trail for one lead → GET /leads/{id}/website-evidence.
 *
 * ENABLED-GATED ON PURPOSE. The Nearby list renders many cards, and fetching evidence for each would
 * be one request per card for a panel almost nobody expands. It loads only once a BD actually opens
 * the evidence for a business.
 */
export function useWebsiteEvidence(leadId: string, enabled: boolean) {
  return useQuery({
    queryKey: queryKeys.websiteEvidence.detail(leadId),
    queryFn: () =>
      apiClient.get(`/leads/${leadId}/website-evidence`).then((r) => r.data.data as WebsiteEvidence),
    enabled,
  });
}

/**
 * A BD confirms no credible website exists → POST /leads/{id}/website-absence.
 *
 * THE ONLY WAY `no-website` IS EVER REACHED. Discovery proposes candidates and the backend refuses
 * the ones that are not this business's, but no automated step concludes absence — a person reads the
 * trail and accepts it. The server independently re-checks that the search is exhausted and answers
 * 409 when it is not, so this hook never has to decide whether the claim is justified.
 *
 * The confirmation writes `website_status`, which feeds the frozen scorer exactly as any human lead
 * edit does, so every computed surface is invalidated alongside the evidence itself.
 */
export function useConfirmWebsiteAbsence(leadId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (note: string | null) =>
      apiClient
        .post(`/leads/${leadId}/website-absence`, { note: note || null })
        .then((r) => r.data.data as WebsiteEvidence),
    onSuccess: (evidence) => {
      queryClient.setQueryData(queryKeys.websiteEvidence.detail(leadId), evidence);
      queryClient.invalidateQueries({ queryKey: queryKeys.nearbyClients.all }); // status + priority chip
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all });
      toast.success("Confirmed — recorded as having no website");
    },
    onError: (err) => {
      // A 409 here is the guard doing its job (evidence not exhausted, or a website turned up after
      // all), so the server's own message is the useful one to show.
      toast.error(getApiErrorMessage(err, "Could not confirm this business has no website"));
    },
  });
}
