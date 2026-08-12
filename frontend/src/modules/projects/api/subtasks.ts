import apiClient from "@/core/api/client";
import type { ApiResource, Paginated } from "@/core/types";
import type { Subtask } from "@/modules/projects/types/subtask";

export async function getSubtasks(taskId: string): Promise<Paginated<Subtask>> {
  const { data } = await apiClient.get<Paginated<Subtask>>(
    `/projects/tasks/${taskId}/subtasks`,
  );
  return data;
}

export async function createSubtask(
  taskId: string,
  payload: {
    name: string;
    assignee_id: number | null;
    status_id: string;
    priority: string;
    effort_score: number;
    deadline?: string | null;
  },
): Promise<Subtask> {
  const { data } = await apiClient.post<ApiResource<Subtask>>(
    `/projects/tasks/${taskId}/subtasks`,
    payload,
  );
  return data.data;
}

export async function updateSubtask(
  id: string,
  payload: Record<string, unknown>,
): Promise<Subtask> {
  const { data } = await apiClient.put<ApiResource<Subtask>>(
    `/projects/subtasks/${id}`,
    payload,
  );
  return data.data;
}

export async function deleteSubtask(id: string): Promise<void> {
  await apiClient.delete(`/projects/subtasks/${id}`);
}
