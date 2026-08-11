"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { addTaskLink, getTaskLinks } from "@/modules/projects/api/links";
import { projectKeys } from "@/modules/projects/lib/query-keys";

export function useTaskLinks(taskId: string) {
  return useQuery({
    queryKey: projectKeys.links(taskId),
    queryFn: () => getTaskLinks(taskId),
    enabled: !!taskId,
  });
}

export function useAddLink(taskId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (url: string) => addTaskLink(taskId, url),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.links(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.task(taskId) });
    },
  });
}
