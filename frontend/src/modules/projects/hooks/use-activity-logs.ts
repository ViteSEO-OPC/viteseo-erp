"use client";

import { useQuery } from "@tanstack/react-query";
import {
  getActivityFeed,
  getActivityLogs,
} from "@/modules/projects/api/activity-logs";
import { projectKeys } from "@/modules/projects/lib/query-keys";
import { STALE } from "@/modules/projects/lib/query-freshness";

export function useActivityLogs(taskId: string) {
  return useQuery({
    queryKey: projectKeys.activity(taskId),
    queryFn: () => getActivityLogs(taskId),
    enabled: !!taskId,
    // One task's History. Appended to by the same events that refresh the task
    // itself, so it does not need its own refetch-on-mount.
    staleTime: STALE.standard,
  });
}

/**
 * The Recent Activity feed. Omit both ids for the platform-wide feed (the PM
 * dashboard), or pass one to scope it (a workspace's page).
 *
 * Short `staleTime`: the feed is ambient context, so it should reflect a
 * teammate's move within a minute without every remount refetching it.
 */
export function useActivityFeed(
  scope: { workspaceId?: string; projectId?: string; limit?: number } = {},
) {
  const { workspaceId, projectId, limit = 8 } = scope;

  const params: Record<string, unknown> = { per_page: limit };
  if (workspaceId) params["filter[workspace_id]"] = workspaceId;
  if (projectId) params["filter[project_id]"] = projectId;

  return useQuery({
    queryKey: projectKeys.activityFeed(params),
    queryFn: () => getActivityFeed(params),
    staleTime: 60_000,
  });
}
