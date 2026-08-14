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
  claimTask,
  createTask,
  deleteTask,
  getClaimableTasks,
  getTask,
  getTasks,
  releaseTask,
  updateTask,
} from "@/modules/projects/api/tasks";
import { projectKeys } from "@/modules/projects/lib/query-keys";
import type { CreateTaskPayload, Task } from "@/modules/projects/types/task";
import { notifyMutationError } from "@/modules/projects/lib/api-error";
import { STALE } from "@/modules/projects/lib/query-freshness";

export function useTasks(projectId: string) {
  return useQuery({
    queryKey: projectKeys.tasks(projectId),
    queryFn: () => getTasks(projectId),
    enabled: !!projectId,
    // The board is patched in place by the project channel's Task*/Status*
    // handlers, so refetching is a backstop rather than the delivery path.
    staleTime: STALE.standard,
  });
}

export function useTask(id: string) {
  return useQuery({
    queryKey: projectKeys.task(id),
    queryFn: () => getTask(id),
    enabled: !!id,
    staleTime: STALE.standard,
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
      staleTime: STALE.standard,
    })),
  });

  const tasks = results.flatMap((r) => r.data?.data ?? []);
  const isLoading = results.some((r) => r.isLoading);
  return { tasks, isLoading };
}

export function useCreateTask(projectId: string) {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't create that task"),
    mutationFn: (payload: CreateTaskPayload) => createTask(projectId, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.tasks(projectId) });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
      qc.invalidateQueries({ queryKey: projectKeys.activityFeedRoot });
      qc.invalidateQueries({ queryKey: projectKeys.claimableRoot });
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
    onError: (err, _vars, ctx) => {
      // Put the board back the way it was — and then say why. A silent revert
      // reads as "the drag didn't register", so the user just tries again.
      if (ctx?.prev) qc.setQueryData(key, ctx.prev);
      notifyMutationError("Couldn't save that change")(err);
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
      // A status change is logged, so the Recent Activity feed has news.
      qc.invalidateQueries({ queryKey: projectKeys.activityFeedRoot });
      // An edit can clear the assignee, which puts the task back on the pool.
      qc.invalidateQueries({ queryKey: projectKeys.claimableRoot });
    },
  });
}

export function useDeleteTask(projectId: string) {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't delete that task"),
    mutationFn: (id: string) => deleteTask(id),
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: projectKeys.tasks(projectId) });
      qc.removeQueries({ queryKey: projectKeys.task(id) });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
      // The task's log rows go with it, so the feed changes on a delete too.
      qc.invalidateQueries({ queryKey: projectKeys.activityFeedRoot });
      qc.invalidateQueries({ queryKey: projectKeys.claimableRoot });
    },
  });
}

/**
 * The open-task pool. Not scoped to your projects — that is the point.
 *
 * `staleTime` is short and deliberate: the pool is contested, so a row that has
 * already been taken should not sit there long enough to be clicked. A lost race
 * still resolves correctly (the server 409s), this just makes it rarer.
 */
export function useClaimableTasks(
  scope: { projectId?: string; limit?: number } = {},
) {
  const { projectId, limit = 10 } = scope;

  const params: Record<string, unknown> = { per_page: limit };
  if (projectId) params["filter[project_id]"] = projectId;

  return useQuery({
    queryKey: projectKeys.claimable(params),
    queryFn: () => getClaimableTasks(params),
    staleTime: 30_000,
  });
}

/**
 * Take an open task.
 *
 * Claiming puts you on the project, so the project list changes too — pickers
 * and "my projects" counts read from it. Only the gaining project's board is
 * refreshed, not every board in the cache.
 */
export function useClaimTask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => claimTask(id),
    onSuccess: (task) => {
      qc.invalidateQueries({ queryKey: projectKeys.claimableRoot });
      // Joining the project changes the project list, not every board on it.
      qc.invalidateQueries({ queryKey: projectKeys.projectsRoot });
      // The board that gains the task, and the task's own bundle.
      qc.invalidateQueries({ queryKey: projectKeys.tasks(task.project_id) });
      qc.invalidateQueries({ queryKey: projectKeys.task(task.id) });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
      qc.invalidateQueries({ queryKey: projectKeys.activityFeedRoot });
    },
  });
}

/** Hand a task back to the pool. */
export function useReleaseTask() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't release that task"),
    mutationFn: (id: string) => releaseTask(id),
    onSuccess: (task) => {
      qc.invalidateQueries({ queryKey: projectKeys.claimableRoot });
      // The board it leaves, and the task's own bundle.
      qc.invalidateQueries({ queryKey: projectKeys.tasks(task.project_id) });
      qc.invalidateQueries({ queryKey: projectKeys.task(task.id) });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
      qc.invalidateQueries({ queryKey: projectKeys.activityFeedRoot });
    },
  });
}
