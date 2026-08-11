"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  getGroupWorkload,
  getUserWorkload,
  getWorkloadAnalysis,
  getWorkloadTasks,
  updateWorkloadCapacity,
} from "@/modules/projects/api/workload";
import { projectKeys, workloadKeys } from "@/modules/projects/lib/query-keys";

/**
 * Live workload for a member, fetched on demand only when a valid assignee is
 * selected in the task modal. Advisory data — it never blocks assignment.
 */
export function useUserWorkload(userId: number | null | undefined) {
  return useQuery({
    queryKey: [...projectKeys.workloads, "user", userId ?? 0],
    queryFn: () => getUserWorkload(userId as number),
    enabled: userId != null && userId > 0,
    // Always refetch on open so PMs see current workload after recent changes.
    staleTime: 0,
  });
}

/**
 * Live workload for a group — same aggregation scoped to the group. Used on the
 * Groups page and for the task modal's group-assignment indicator.
 */
export function useGroupWorkload(groupId: string | null | undefined) {
  return useQuery({
    queryKey: [...projectKeys.workloads, "group", groupId ?? ""],
    queryFn: () => getGroupWorkload(groupId as string),
    enabled: !!groupId,
    staleTime: 0,
  });
}

export function useWorkloadAnalysis(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...workloadKeys.analysis, params ?? {}],
    queryFn: () => getWorkloadAnalysis(params),
  });
}

export function useWorkloadTasks(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...workloadKeys.tasks, params ?? {}],
    queryFn: () => getWorkloadTasks(params),
  });
}

/**
 * Retuning capacity re-derives every workload level, so it invalidates the
 * analysis AND the per-member/group indicators shown in the task modal.
 */
export function useUpdateWorkloadCapacity() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: {
      max_capacity: number;
      light_max: number;
      moderate_max: number;
    }) => updateWorkloadCapacity(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["projects", "workload"] });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
    },
  });
}
