"use client";

import {
  useMutation,
  useQueries,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import {
  createStatus,
  deleteStatus,
  getStatuses,
  updateStatus,
} from "@/modules/projects/api/statuses";
import { projectKeys } from "@/modules/projects/lib/query-keys";
import type { ProjectStatus } from "@/modules/projects/types/project";
import { notifyMutationError } from "@/modules/projects/lib/api-error";

export function useStatuses(projectId: string) {
  return useQuery({
    queryKey: projectKeys.statuses(projectId),
    queryFn: () => getStatuses(projectId),
    enabled: !!projectId,
  });
}

export function useCreateStatus(projectId: string) {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't create that status"),
    mutationFn: (payload: { name: string; color: string }) =>
      createStatus(projectId, payload),
    onSuccess: () =>
      qc.invalidateQueries({ queryKey: projectKeys.statuses(projectId) }),
  });
}

export function useUpdateStatus(projectId: string) {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't save that status"),
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: { name?: string; color?: string };
    }) => updateStatus(id, payload),
    onSuccess: () =>
      qc.invalidateQueries({ queryKey: projectKeys.statuses(projectId) }),
  });
}

export function useDeleteStatus(projectId: string) {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't delete that status"),
    mutationFn: (id: string) => deleteStatus(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.statuses(projectId) });
      // Deleting a status falls its tasks/subtasks back to "no status".
      qc.invalidateQueries({ queryKey: projectKeys.tasks(projectId) });
      qc.invalidateQueries({ queryKey: ["projects", "tasks"] });
    },
  });
}

/** Statuses for many projects at once, keyed by project id (for workspace views). */
export function useWorkspaceStatuses(
  projectIds: string[],
): Map<string, ProjectStatus[]> {
  const results = useQueries({
    queries: projectIds.map((pid) => ({
      queryKey: projectKeys.statuses(pid),
      queryFn: () => getStatuses(pid),
      enabled: !!pid,
    })),
  });

  const map = new Map<string, ProjectStatus[]>();
  projectIds.forEach((pid, idx) => map.set(pid, results[idx]?.data ?? []));
  return map;
}
