import apiClient from "@/core/api/client";
import type { ApiResource, Paginated } from "@/core/types";
import type { Link } from "@/modules/projects/types/link";

export async function getTaskLinks(taskId: string): Promise<Paginated<Link>> {
  const { data } = await apiClient.get<Paginated<Link>>(
    `/projects/tasks/${taskId}/links`,
  );
  return data;
}

export async function addTaskLink(taskId: string, url: string): Promise<Link> {
  const { data } = await apiClient.post<ApiResource<Link>>(
    `/projects/tasks/${taskId}/links`,
    { url },
  );
  return data.data;
}
