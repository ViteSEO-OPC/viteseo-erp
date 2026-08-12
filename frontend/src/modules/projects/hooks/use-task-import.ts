"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import {
  commitTaskImport,
  previewTaskImport,
  revalidateTaskImport,
} from "@/modules/projects/api/task-import";
import { projectKeys } from "@/modules/projects/lib/query-keys";
import type { ImportPreviewTask } from "@/modules/projects/types/task-import";

/**
 * Dry-run an uploaded plan.
 *
 * No cache invalidation: a preview writes nothing, so there is nothing to
 * refresh. Errors are rendered in the dialog rather than toasted — the PM is
 * looking straight at the thing that failed.
 */
export function usePreviewTaskImport(projectId: string) {
  return useMutation({
    mutationFn: ({
      file,
      onUploadProgress,
      signal,
    }: {
      file: File;
      onUploadProgress?: (percent: number) => void;
      signal?: AbortSignal;
    }) => previewTaskImport(projectId, file, { onUploadProgress, signal }),
  });
}

/**
 * Re-check corrected rows against the server's rules. Writes nothing, so no
 * invalidation.
 */
export function useRevalidateTaskImport(projectId: string) {
  return useMutation({
    mutationFn: (tasks: ImportPreviewTask[]) =>
      revalidateTaskImport(projectId, tasks),
  });
}

/**
 * Create the approved plan.
 *
 * Invalidates the same set as useCreateTask — one import is many creations, and
 * it moves the board, everyone's workload, the activity feed, and (for any task
 * left unassigned) the open task pool.
 */
export function useCommitTaskImport(projectId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (tasks: ImportPreviewTask[]) =>
      commitTaskImport(projectId, tasks),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.tasks(projectId) });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
      qc.invalidateQueries({ queryKey: projectKeys.activityFeedRoot });
      qc.invalidateQueries({ queryKey: projectKeys.claimableRoot });
    },
  });
}
