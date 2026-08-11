"use client";

import {
  useMutation,
  useQueries,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import type { Paginated } from "@/core/types";
import { celebrateTask } from "@/core/lib/confetti";
import {
  createTask,
  deleteTask,
  getTask,
  getTasks,
  updateTask,
} from "@/modules/projects/api/tasks";
import { projectKeys } from "@/modules/projects/lib/query-keys";
import type { CreateTaskPayload, Task } from "@/modules/projects/types/task";

export function useTasks(projectId: string) {
  return useQuery({
    queryKey: projectKeys.tasks(projectId),
    queryFn: () => getTasks(projectId),
    enabled: !!projectId,
  });
}

export function useTask(id: string) {
  return useQuery({
    queryKey: projectKeys.task(id),
    queryFn: () => getTask(id),
    enabled: !!id,
  });
}

/** Fetch and merge tasks across several projects (for workspace-level views). */
export function useWorkspaceTasks(projectIds: string[]): {
  tasks: Task[];
  isLoading: boolean;
} {
  const results = useQueries({
    queries: projectIds.map((pid) => ({
      queryKey: projectKeys.tasks(pid),
      queryFn: () => getTasks(pid),
      enabled: !!pid,
    })),
  });

  const tasks = results.flatMap((r) => r.data?.data ?? []);
  const isLoading = results.some((r) => r.isLoading);
  return { tasks, isLoading };
}

export function useCreateTask(projectId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateTaskPayload) =>
      createTask(projectId, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.tasks(projectId) });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
    },
  });
}

export function useUpdateTask(projectId: string) {
  const qc = useQueryClient();
  const key = projectKeys.tasks(projectId);
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: Partial<CreateTaskPayload>;
    }) => updateTask(id, payload),
    // Optimistically apply the change (e.g. a drag-and-drop status move) so the
    // board updates instantly; roll back on error.
    onMutate: async ({ id, payload }) => {
      await qc.cancelQueries({ queryKey: key });
      const prev = qc.getQueryData<Paginated<Task>>(key);
      if (prev) {
        qc.setQueryData<Paginated<Task>>(key, {
          ...prev,
          data: prev.data.map((t) => (t.id === id ? { ...t, ...payload } : t)),
        });
      }
      return { prev };
    },
    onError: (_err, _vars, ctx) => {
      if (ctx?.prev) qc.setQueryData(key, ctx.prev);
    },
    onSuccess: (data, { payload }) => {
      // Celebrate when this update transitioned the task into "done".
      if (payload.status_id && data?.status?.key === "done") {
        celebrateTask(data.id);
      }
    },
    onSettled: (_data, _err, { id }) => {
      qc.invalidateQueries({ queryKey: key });
      // Refresh the single-task detail (status/priority/etc. + new activity log)
      // so an open task view reflects the change.
      qc.invalidateQueries({ queryKey: projectKeys.task(id) });
      // Assignment/status changes move effort between members.
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
    },
  });
}

export function useDeleteTask(projectId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => deleteTask(id),
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: projectKeys.tasks(projectId) });
      qc.removeQueries({ queryKey: projectKeys.task(id) });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
    },
  });
}
