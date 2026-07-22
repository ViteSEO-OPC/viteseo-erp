"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type {
  ServiceArea,
  ServiceAreaLevel,
  ServiceAreaSummary,
} from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";

/**
 * Target Service-Area registry (Admin/SA only — `GET /service-areas` is role-guarded). Returns the
 * PO-managed areas plus a server-side, data-only summary of how many leads fall in a target area
 * (`Lead::scopeInTargetLocation` on the backend). No score computation happens on the client.
 */
export function useServiceAreas() {
  const query = useQuery<{ data: ServiceArea[]; meta: ServiceAreaSummary }>({
    queryKey: queryKeys.serviceAreas.list(),
    queryFn: () => apiClient.get("/service-areas").then((r) => r.data),
  });

  return {
    areas: query.data?.data,
    summary: query.data?.meta,
    isLoading: query.isLoading,
    isError: query.isError,
  };
}

export function useCreateServiceArea() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: { name: string; level: ServiceAreaLevel }) =>
      apiClient.post("/service-areas", payload).then((r) => r.data.data as ServiceArea),
    onSuccess: (area) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.serviceAreas.all });
      toast.success(`Target area "${area.name}" added`);
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Failed to add target area")),
  });
}

export function useDeleteServiceArea() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => apiClient.delete(`/service-areas/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.serviceAreas.all });
      toast.success("Target area removed");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Failed to remove target area")),
  });
}
