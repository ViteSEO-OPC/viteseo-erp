import apiClient from "@/core/api/client";
import type { ApiResource, Paginated } from "@/core/types";
import type {
  BulkApproveResult,
  PendingSubtask,
  PendingTask,
} from "@/modules/projects/types/approval";
import type { Subtask } from "@/modules/projects/types/subtask";
import type { Task } from "@/modules/projects/types/task";

// Approvals — the PM/Admin review queue for member-submitted work. Reviewers
// EDIT a proposal through updateTask / updateSubtask (in ./tasks and
// ./subtasks); these endpoints only carry the decision.
export async function getPendingTasks(
  params?: Record<string, unknown>,
): Promise<Paginated<PendingTask>> {
  const { data } = await apiClient.get<Paginated<PendingTask>>(
    "/projects/admin/approvals/tasks",
    { params },
  );
  return data;
}

export async function getPendingSubtasks(
  params?: Record<string, unknown>,
): Promise<Paginated<PendingSubtask>> {
  const { data } = await apiClient.get<Paginated<PendingSubtask>>(
    "/projects/admin/approvals/subtasks",
    { params },
  );
  return data;
}

export async function approveTask(id: string): Promise<Task> {
  const { data } = await apiClient.post<ApiResource<Task>>(
    `/projects/admin/approvals/tasks/${id}/approve`,
  );
  return data.data;
}

/** Approve a whole selection at once; returns what went through vs was skipped. */
export async function bulkApproveTasks(ids: string[]): Promise<BulkApproveResult> {
  const { data } = await apiClient.post<ApiResource<BulkApproveResult>>(
    "/projects/admin/approvals/tasks/bulk-approve",
    { ids },
  );
  return data.data;
}

export async function bulkApproveSubtasks(ids: string[]): Promise<BulkApproveResult> {
  const { data } = await apiClient.post<ApiResource<BulkApproveResult>>(
    "/projects/admin/approvals/subtasks/bulk-approve",
    { ids },
  );
  return data.data;
}

export async function rejectTask(id: string, reason: string): Promise<Task> {
  const { data } = await apiClient.post<ApiResource<Task>>(
    `/projects/admin/approvals/tasks/${id}/reject`,
    { reason },
  );
  return data.data;
}

export async function approveSubtask(id: string): Promise<Subtask> {
  const { data } = await apiClient.post<ApiResource<Subtask>>(
    `/projects/admin/approvals/subtasks/${id}/approve`,
  );
  return data.data;
}

export async function rejectSubtask(id: string, reason: string): Promise<Subtask> {
  const { data } = await apiClient.post<ApiResource<Subtask>>(
    `/projects/admin/approvals/subtasks/${id}/reject`,
    { reason },
  );
  return data.data;
}

/** Author-side: send a revised (rejected) proposal back for review. */
export async function resubmitTask(id: string): Promise<Task> {
  const { data } = await apiClient.post<ApiResource<Task>>(
    `/projects/tasks/${id}/resubmit`,
  );
  return data.data;
}

export async function resubmitSubtask(id: string): Promise<Subtask> {
  const { data } = await apiClient.post<ApiResource<Subtask>>(
    `/projects/subtasks/${id}/resubmit`,
  );
  return data.data;
}
