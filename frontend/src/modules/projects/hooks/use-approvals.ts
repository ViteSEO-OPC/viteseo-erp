"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  approveSubtask,
  approveTask,
  bulkApproveSubtasks,
  bulkApproveTasks,
  getPendingSubtasks,
  getPendingTasks,
  rejectSubtask,
  rejectTask,
  resubmitSubtask,
  resubmitTask,
} from "@/modules/projects/api/approvals";
import { approvalKeys, projectKeys } from "@/modules/projects/lib/query-keys";
import { notifyMutationError } from "@/modules/projects/lib/api-error";
import { STALE } from "@/modules/projects/lib/query-freshness";

/**
 * A decision (or a resubmission) moves work between the queue and the board, so
 * it invalidates both — plus workloads, which a newly approved item feeds into.
 */
function invalidateAfterReview(qc: ReturnType<typeof useQueryClient>): void {
  qc.invalidateQueries({ queryKey: approvalKeys.tasks });
  qc.invalidateQueries({ queryKey: approvalKeys.subtasks });
  // A decision lands the item on its board and rewrites its detail. The
  // shared helper has no id to narrow by (bulk decisions cover many), so the
  // two roots stand — both are far tighter than they were: boards no longer
  // sit under the projects root, so the project list is left alone.
  qc.invalidateQueries({ queryKey: projectKeys.tasksRoot });
  qc.invalidateQueries({ queryKey: projectKeys.boardsRoot });
  qc.invalidateQueries({ queryKey: projectKeys.workloads });
  qc.invalidateQueries({ queryKey: projectKeys.activityFeedRoot });
}

/** `enabled` is false for non-managers — the endpoint 403s for them. */
export function usePendingTasks(
  params?: Record<string, unknown>,
  enabled = true,
) {
  return useQuery({
    queryKey: [...approvalKeys.tasks, params ?? {}],
    queryFn: () => getPendingTasks(params),
    enabled,
    // No channel behind the queue — a short window keeps it live.
    staleTime: STALE.short,
  });
}

/** `enabled` is false for non-managers — the endpoint 403s for them. */
export function usePendingSubtasks(
  params?: Record<string, unknown>,
  enabled = true,
) {
  return useQuery({
    queryKey: [...approvalKeys.subtasks, params ?? {}],
    queryFn: () => getPendingSubtasks(params),
    enabled,
    staleTime: STALE.short,
  });
}

export function useApproveTask() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't approve that task"),
    mutationFn: (id: string) => approveTask(id),
    onSuccess: () => invalidateAfterReview(qc),
  });
}

export function useBulkApproveTasks() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't approve the selected tasks"),
    mutationFn: (ids: string[]) => bulkApproveTasks(ids),
    onSuccess: () => invalidateAfterReview(qc),
  });
}

export function useBulkApproveSubtasks() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't approve the selected sub-tasks"),
    mutationFn: (ids: string[]) => bulkApproveSubtasks(ids),
    onSuccess: () => invalidateAfterReview(qc),
  });
}

export function useRejectTask() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't reject that task"),
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      rejectTask(id, reason),
    onSuccess: () => invalidateAfterReview(qc),
  });
}

export function useApproveSubtask() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't approve that sub-task"),
    mutationFn: (id: string) => approveSubtask(id),
    onSuccess: () => invalidateAfterReview(qc),
  });
}

export function useRejectSubtask() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't reject that sub-task"),
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      rejectSubtask(id, reason),
    onSuccess: () => invalidateAfterReview(qc),
  });
}

export function useResubmitTask() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't resubmit that task"),
    mutationFn: (id: string) => resubmitTask(id),
    onSuccess: () => invalidateAfterReview(qc),
  });
}

export function useResubmitSubtask() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't resubmit that sub-task"),
    mutationFn: (id: string) => resubmitSubtask(id),
    onSuccess: () => invalidateAfterReview(qc),
  });
}
