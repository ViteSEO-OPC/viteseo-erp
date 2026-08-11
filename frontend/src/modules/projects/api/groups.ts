import apiClient from "@/core/api/client";
import type { ApiResource, Paginated } from "@/core/types";
import type { Group } from "@/modules/projects/types/group";

// Groups (scoped to a project)
export async function getGroups(projectId?: string): Promise<Paginated<Group>> {
  const { data } = await apiClient.get<Paginated<Group>>("/projects/groups", {
    params: {
      per_page: 100,
      ...(projectId ? { "filter[project_id]": projectId } : {}),
    },
  });
  return data;
}

export async function getGroup(id: string): Promise<Group> {
  const { data } = await apiClient.get<ApiResource<Group>>(
    `/projects/groups/${id}`,
  );
  return data.data;
}

export async function createGroup(payload: {
  name: string;
  color: string;
  leader_id: number;
  member_ids: number[];
}): Promise<Group> {
  const { data } = await apiClient.post<ApiResource<Group>>(
    "/projects/groups",
    payload,
  );
  return data.data;
}

export async function updateGroup(
  id: string,
  payload: { name?: string; color?: string; leader_id?: number },
): Promise<Group> {
  const { data } = await apiClient.put<ApiResource<Group>>(
    `/projects/groups/${id}`,
    payload,
  );
  return data.data;
}

export async function deleteGroup(id: string): Promise<void> {
  await apiClient.delete(`/projects/groups/${id}`);
}

export async function addGroupMember(id: string, userId: number): Promise<void> {
  await apiClient.post(`/projects/groups/${id}/members`, { user_id: userId });
}

export async function removeGroupMember(id: string, userId: number): Promise<void> {
  await apiClient.delete(`/projects/groups/${id}/members/${userId}`);
}
