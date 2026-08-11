"use client";

import { useQuery } from "@tanstack/react-query";
import { getActivityLogs } from "@/modules/projects/api/activity-logs";
import { projectKeys } from "@/modules/projects/lib/query-keys";

export function useActivityLogs(taskId: string) {
  return useQuery({
    queryKey: projectKeys.activity(taskId),
    queryFn: () => getActivityLogs(taskId),
    enabled: !!taskId,
  });
}
