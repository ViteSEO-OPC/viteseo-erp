import apiClient from "@/core/api/client";
import type { ApiResource, Paginated } from "@/core/types";
import type { Workspace } from "@/modules/projects/types/workspace";

export async function getWorkspaces(
  params?: Record<string, unknown>,
): Promise<Paginated<Workspace>> {
  const { data } = await apiClient.get<Paginated<Workspace>>(
    "/projects/workspaces",
    { params },
  );
  return data;
}

export async function getWorkspace(id: string): Promise<Workspace> {
  const { data } = await apiClient.get<ApiResource<Workspace>>(
    `/projects/workspaces/${id}`,
  );
  return data.data;
}

export async function createWorkspace(payload: {
  name: string;
  description?: string;
}): Promise<Workspace> {
  const { data } = await apiClient.post<ApiResource<Workspace>>(
    "/projects/workspaces",
    payload,
  );
  return data.data;
}

export async function updateWorkspace(
  id: string,
  payload: { name?: string; description?: string | null },
): Promise<Workspace> {
  const { data } = await apiClient.put<ApiResource<Workspace>>(
    `/projects/workspaces/${id}`,
    payload,
  );
  return data.data;
}

export async function deleteWorkspace(id: string): Promise<void> {
  await apiClient.delete(`/projects/workspaces/${id}`);
}
