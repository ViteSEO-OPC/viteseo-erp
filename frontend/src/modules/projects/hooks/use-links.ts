"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { addTaskLink, getTaskLinks } from "@/modules/projects/api/links";
import { projectKeys } from "@/modules/projects/lib/query-keys";
import { notifyMutationError } from "@/modules/projects/lib/api-error";

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
    onError: notifyMutationError("Couldn't add that link"),
    mutationFn: (url: string) => addTaskLink(taskId, url),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.links(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.task(taskId) });
    },
  });
}
