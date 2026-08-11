import apiClient from "@/core/api/client";
import type { Paginated } from "@/core/types";
import type {
  ActivityFeedEntry,
  ActivityLog,
} from "@/modules/projects/types/activity-log";

export async function getActivityLogs(taskId: string): Promise<Paginated<ActivityLog>> {
  const { data } = await apiClient.get<Paginated<ActivityLog>>(
    `/projects/tasks/${taskId}/activity-logs`,
  );
  return data;
}

/**
 * The Recent Activity feed: task and sub-task events across every project the
 * caller can see. Pass `filter[workspace_id]` (or `filter[project_id]`) to scope
 * it to one workspace's page.
 */
export async function getActivityFeed(
  params?: Record<string, unknown>,
): Promise<Paginated<ActivityFeedEntry>> {
  const { data } = await apiClient.get<Paginated<ActivityFeedEntry>>(
    "/projects/activity-logs",
    { params },
  );
  return data;
}
