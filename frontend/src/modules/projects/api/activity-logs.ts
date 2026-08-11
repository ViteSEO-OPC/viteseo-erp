import apiClient from "@/core/api/client";
import type { Paginated } from "@/core/types";
import type { ActivityLog } from "@/modules/projects/types/activity-log";

export async function getActivityLogs(taskId: string): Promise<Paginated<ActivityLog>> {
  const { data } = await apiClient.get<Paginated<ActivityLog>>(
    `/projects/tasks/${taskId}/activity-logs`,
  );
  return data;
}
