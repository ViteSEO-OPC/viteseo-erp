import apiClient from "@/core/api/client";
import type { ApiResource, Paginated } from "@/core/types";
import type {
  ClaimableTask,
  CreateTaskPayload,
  Task,
} from "@/modules/projects/types/task";

export async function getTasks(
  projectId: string,
  params?: Record<string, unknown>,
): Promise<Paginated<Task>> {
  const { data } = await apiClient.get<Paginated<Task>>(
    `/projects/projects/${projectId}/tasks`,
    { params: { per_page: 100, ...params } },
  );
  return data;
}

export async function getTask(id: string): Promise<Task> {
  const { data } = await apiClient.get<ApiResource<Task>>(
    `/projects/tasks/${id}`,
  );
  return data.data;
}

export async function createTask(
  projectId: string,
  payload: CreateTaskPayload,
): Promise<Task> {
  const { data } = await apiClient.post<ApiResource<Task>>(
    `/projects/projects/${projectId}/tasks`,
    payload,
  );
  return data.data;
}

export async function updateTask(
  id: string,
  payload: Partial<CreateTaskPayload>,
): Promise<Task> {
  const { data } = await apiClient.put<ApiResource<Task>>(
    `/projects/tasks/${id}`,
    payload,
  );
  return data.data;
}

export async function deleteTask(id: string): Promise<void> {
  await apiClient.delete(`/projects/tasks/${id}`);
}

/**
 * The open-task pool: approved work nobody owns yet, across every project —
 * including ones the caller isn't a member of. That is the point: with one
 * person per discipline in a pod, work only gets picked up across pods.
 */
export async function getClaimableTasks(
  params?: Record<string, unknown>,
): Promise<Paginated<ClaimableTask>> {
  const { data } = await apiClient.get<Paginated<ClaimableTask>>(
    "/projects/tasks/claimable",
    { params },
  );
  return data;
}

/** Take an open task. 409 when someone else got there first. */
export async function claimTask(id: string): Promise<Task> {
  const { data } = await apiClient.post<ApiResource<Task>>(
    `/projects/tasks/${id}/claim`,
  );
  return data.data;
}

/** Hand a task back to the pool. */
export async function releaseTask(id: string): Promise<Task> {
  const { data } = await apiClient.post<ApiResource<Task>>(
    `/projects/tasks/${id}/release`,
  );
  return data.data;
}
