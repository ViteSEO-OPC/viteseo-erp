"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  addTaskComment,
  getTaskComments,
  updateComment,
} from "@/modules/projects/api/comments";
import { projectKeys } from "@/modules/projects/lib/query-keys";

export function useTaskComments(taskId: string) {
  return useQuery({
    queryKey: projectKeys.comments(taskId),
    queryFn: () => getTaskComments(taskId),
    enabled: !!taskId,
  });
}

export function useAddComment(taskId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (content: string) =>
      addTaskComment(taskId, content),
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
    mutationFn: ({ id, content }: { id: string; content: string }) =>
      updateComment(id, content),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.comments(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.task(taskId) });
    },
  });
}
