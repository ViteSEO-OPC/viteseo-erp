import apiClient from "@/core/api/client";
import type { ApiResource, Paginated } from "@/core/types";
import type { Project } from "@/modules/projects/types/project";

export async function getProjects(
  workspaceId?: string,
): Promise<Paginated<Project>> {
  const { data } = await apiClient.get<Paginated<Project>>(
    "/projects/projects",
    {
      params: workspaceId ? { "filter[workspace_id]": workspaceId } : {},
    },
  );
  return data;
}

export async function getProject(id: string): Promise<Project> {
  const { data } = await apiClient.get<ApiResource<Project>>(
    `/projects/projects/${id}`,
  );
  return data.data;
}

export async function createProject(payload: {
  workspace_id: string;
  name: string;
  description?: string;
  priority?: "low" | "medium" | "high";
  color?: string;
}): Promise<Project> {
  const { data } = await apiClient.post<ApiResource<Project>>(
    "/projects/projects",
    payload,
  );
  return data.data;
}

export async function updateProject(
  id: string,
  payload: {
    name?: string;
    description?: string | null;
    priority?: "low" | "medium" | "high" | null;
    color?: string | null;
    status?: "active" | "done";
  },
): Promise<Project> {
  const { data } = await apiClient.put<ApiResource<Project>>(
    `/projects/projects/${id}`,
    payload,
  );
  return data.data;
}

export async function deleteProject(id: string): Promise<void> {
  await apiClient.delete(`/projects/projects/${id}`);
}

export async function addProjectMember(id: string, userId: number): Promise<void> {
  await apiClient.post(`/projects/projects/${id}/members`, { user_id: userId });
}

export async function removeProjectMember(id: string, userId: number): Promise<void> {
  await apiClient.delete(`/projects/projects/${id}/members/${userId}`);
}
