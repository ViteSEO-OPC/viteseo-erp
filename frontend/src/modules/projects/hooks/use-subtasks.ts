"use client";

import {
  useMutation,
  useQueries,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import {
  createSubtask,
  deleteSubtask,
  getSubtasks,
  updateSubtask,
} from "@/modules/projects/api/subtasks";
import { projectKeys } from "@/modules/projects/lib/query-keys";
import type { Subtask } from "@/modules/projects/types/subtask";

/** Fetch and merge subtasks across many tasks (for workspace-level views). */
export function useWorkspaceSubtasks(taskIds: string[]): {
  subtasks: Subtask[];
  isLoading: boolean;
} {
  const results = useQueries({
    queries: taskIds.map((tid) => ({
      queryKey: projectKeys.subtasks(tid),
      queryFn: () => getSubtasks(tid),
      enabled: !!tid,
    })),
  });

  const subtasks = results.flatMap((r) => r.data?.data ?? []);
  const isLoading = results.some((r) => r.isLoading);
  return { subtasks, isLoading };
}

export function useSubtasks(taskId: string) {
  return useQuery({
    queryKey: projectKeys.subtasks(taskId),
    queryFn: () => getSubtasks(taskId),
    enabled: !!taskId,
  });
}

export function useCreateSubtask(taskId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: {
      name: string;
      assignee_id: number | null;
      status_id: string;
      priority: string;
      effort_score: number;
      deadline?: string | null;
    }) => createSubtask(taskId, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.subtasks(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.task(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
    },
  });
}

export function useUpdateSubtask(taskId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: Record<string, unknown>;
    }) => updateSubtask(id, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.subtasks(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.task(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
    },
  });
}

export function useDeleteSubtask(taskId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => deleteSubtask(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.subtasks(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.task(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
    },
  });
}
