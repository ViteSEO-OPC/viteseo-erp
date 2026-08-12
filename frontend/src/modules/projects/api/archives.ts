import apiClient from "@/core/api/client";
import type { Paginated } from "@/core/types";
import type {
  ArchivedProject,
  ArchivedSubtask,
  ArchivedTask,
  ArchivedWorkspace,
} from "@/modules/projects/types/archive";

// Archives (admin only)
export async function getArchivedWorkspaces(
  params?: Record<string, unknown>,
): Promise<Paginated<ArchivedWorkspace>> {
  const { data } = await apiClient.get<Paginated<ArchivedWorkspace>>(
    "/projects/admin/archives/workspaces",
    { params },
  );
  return data;
}

export async function restoreWorkspace(id: string): Promise<void> {
  await apiClient.post(`/projects/admin/archives/workspaces/${id}/restore`);
}

export async function getArchivedProjects(
  params?: Record<string, unknown>,
): Promise<Paginated<ArchivedProject>> {
  const { data } = await apiClient.get<Paginated<ArchivedProject>>(
    "/projects/admin/archives/projects",
    { params },
  );
  return data;
}

export async function restoreProject(id: string): Promise<void> {
  await apiClient.post(`/projects/admin/archives/projects/${id}/restore`);
}

export async function getArchivedTasks(
  params?: Record<string, unknown>,
): Promise<Paginated<ArchivedTask>> {
  const { data } = await apiClient.get<Paginated<ArchivedTask>>(
    "/projects/admin/archives/tasks",
    { params },
  );
  return data;
}

export async function restoreTask(id: string): Promise<void> {
  await apiClient.post(`/projects/admin/archives/tasks/${id}/restore`);
}

export async function getArchivedSubtasks(
  params?: Record<string, unknown>,
): Promise<Paginated<ArchivedSubtask>> {
  const { data } = await apiClient.get<Paginated<ArchivedSubtask>>(
    "/projects/admin/archives/subtasks",
    { params },
  );
  return data;
}

export async function restoreSubtask(id: string): Promise<void> {
  await apiClient.post(`/projects/admin/archives/subtasks/${id}/restore`);
}
