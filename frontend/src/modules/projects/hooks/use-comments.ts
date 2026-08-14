"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  addTaskComment,
  getTaskComments,
  updateComment,
} from "@/modules/projects/api/comments";
import { projectKeys } from "@/modules/projects/lib/query-keys";
import { notifyMutationError } from "@/modules/projects/lib/api-error";
import { STALE } from "@/modules/projects/lib/query-freshness";

export function useTaskComments(taskId: string) {
  return useQuery({
    queryKey: projectKeys.comments(taskId),
    queryFn: () => getTaskComments(taskId),
    enabled: !!taskId,
    // Kept live by the project channel's Comment* handlers.
    staleTime: STALE.standard,
  });
}

export function useAddComment(taskId: string) {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't post that comment"),
    mutationFn: (content: string) => addTaskComment(taskId, content),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.comments(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.task(taskId) });
    },
  });
}
/** Edit a comment. Only its author may — the server enforces that too. */

export function useUpdateComment(taskId: string) {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't save that comment"),
    mutationFn: ({ id, content }: { id: string; content: string }) =>
      updateComment(id, content),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.comments(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.task(taskId) });
    },
  });
}
