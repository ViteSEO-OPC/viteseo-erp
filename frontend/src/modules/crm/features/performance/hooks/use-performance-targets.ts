"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";

/**
 * Goal Management — the performance targets that supply the denominators behind Personal Target and
 * the Quarter Targets tiles.
 *
 * Every mutation is authorized server-side by `crm.performance.manage` (Admin + Super Admin); the
 * UI hides the surface from a BD but the server is what refuses them.
 */

export type TargetPeriod = "month" | "quarter";

/** Mirrors the backend PerformanceTargetMetric enum — the closed set of measurable goals. */
export type TargetMetric =
  | "converted_leads"
  | "leads_contacted"
  | "consultations"
  | "confirmed_clients";

export type PerformanceTarget = {
  id: string;
  userId: string;
  userName?: string | null;
  periodType: TargetPeriod;
  periodStart: string;
  metric: TargetMetric;
  metricLabel: string;
  targetValue: number;
  isActive: boolean;
};

export type TargetPayload = {
  user_id: string;
  period_type: TargetPeriod;
  period_start: string;
  metric: TargetMetric;
  target_value: number;
};

const ENDPOINT = "/settings/performance-targets";
const KEY = ["performance-targets"] as const;

export function usePerformanceTargets() {
  const query = useQuery({
    queryKey: KEY,
    queryFn: async (): Promise<PerformanceTarget[]> => {
      const res = await apiClient.get(ENDPOINT);
      return res.data.data as PerformanceTarget[];
    },
  });

  return {
    targets: query.data ?? [],
    isLoading: query.isLoading,
    isError: query.isError,
    retry: query.refetch,
  };
}

export function useSaveTarget() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: TargetPayload) => {
      const res = await apiClient.post(ENDPOINT, payload);
      return res.data.data as PerformanceTarget;
    },
    onSuccess: () => {
      // The saved target changes the denominators the performance surfaces read, so their caches
      // are invalidated alongside the target list.
      queryClient.invalidateQueries({ queryKey: KEY });
      queryClient.invalidateQueries({ queryKey: ["dashboard"] });
      queryClient.invalidateQueries({ queryKey: ["performance"] });
      toast.success("Target saved");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Could not save the target")),
  });
}

export function useUpdateTarget() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, ...body }: { id: string; target_value?: number; is_active?: boolean }) => {
      const res = await apiClient.patch(`${ENDPOINT}/${id}`, body);
      return res.data.data as PerformanceTarget;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: KEY });
      queryClient.invalidateQueries({ queryKey: ["dashboard"] });
      queryClient.invalidateQueries({ queryKey: ["performance"] });
      toast.success("Target updated");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Could not update the target")),
  });
}

export function useDeleteTarget() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`${ENDPOINT}/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: KEY });
      queryClient.invalidateQueries({ queryKey: ["dashboard"] });
      queryClient.invalidateQueries({ queryKey: ["performance"] });
      toast.success("Target removed");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Could not remove the target")),
  });
}
