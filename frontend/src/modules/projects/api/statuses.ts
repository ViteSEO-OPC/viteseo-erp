import apiClient from "@/core/api/client";
import type { ApiResource } from "@/core/types";
import type { ProjectStatus } from "@/modules/projects/types/project";

export async function getStatuses(projectId: string): Promise<ProjectStatus[]> {
  const { data } = await apiClient.get<ApiResource<ProjectStatus[]>>(
    `/projects/projects/${projectId}/statuses`,
  );
  return data.data;
}

export async function createStatus(
  projectId: string,
  payload: { name: string; color: string },
): Promise<ProjectStatus> {
  const { data } = await apiClient.post<ApiResource<ProjectStatus>>(
    `/projects/projects/${projectId}/statuses`,
    payload,
  );
  return data.data;
}

export async function updateStatus(
  id: string,
  payload: { name?: string; color?: string },
): Promise<ProjectStatus> {
  const { data } = await apiClient.put<ApiResource<ProjectStatus>>(
    `/projects/statuses/${id}`,
    payload,
  );
  return data.data;
}

export async function deleteStatus(id: string): Promise<void> {
  await apiClient.delete(`/projects/statuses/${id}`);
}
