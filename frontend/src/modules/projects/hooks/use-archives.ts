"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  getArchivedProjects,
  getArchivedSubtasks,
  getArchivedTasks,
  getArchivedWorkspaces,
  restoreProject,
  restoreSubtask,
  restoreTask,
  restoreWorkspace,
} from "@/modules/projects/api/archives";
import { archiveKeys, projectKeys } from "@/modules/projects/lib/query-keys";
import { notifyMutationError } from "@/modules/projects/lib/api-error";
import { STALE } from "@/modules/projects/lib/query-freshness";

export function useArchivedWorkspaces(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...archiveKeys.workspaces, params ?? {}],
    queryFn: () => getArchivedWorkspaces(params),
    staleTime: STALE.standard,
  });
}

export function useRestoreWorkspace() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't restore that workspace"),
    mutationFn: (id: string) => restoreWorkspace(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: archiveKeys.workspaces });
      qc.invalidateQueries({ queryKey: projectKeys.workspaces });
    },
  });
}

export function useArchivedProjects(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...archiveKeys.projects, params ?? {}],
    queryFn: () => getArchivedProjects(params),
    staleTime: STALE.standard,
  });
}

export function useRestoreProject() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't restore that project"),
    mutationFn: (id: string) => restoreProject(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: archiveKeys.projects });
      qc.invalidateQueries({ queryKey: projectKeys.projects("all") });
    },
  });
}

export function useArchivedTasks(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...archiveKeys.tasks, params ?? {}],
    queryFn: () => getArchivedTasks(params),
    staleTime: STALE.standard,
  });
}

export function useRestoreTask() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't restore that task"),
    mutationFn: (id: string) => restoreTask(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: archiveKeys.tasks });
      // Restored task reappears on its project board. The id is all we have
      // here (no project on the response), so this is one of the few places
      // the board root is the right target.
      qc.invalidateQueries({ queryKey: projectKeys.boardsRoot });
    },
  });
}

export function useArchivedSubtasks(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...archiveKeys.subtasks, params ?? {}],
    queryFn: () => getArchivedSubtasks(params),
    staleTime: STALE.standard,
  });
}

export function useRestoreSubtask() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't restore that sub-task"),
    mutationFn: (id: string) => restoreSubtask(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: archiveKeys.subtasks });
      // Restored subtask reappears under its parent task — which the restore
      // response does not name, so the task root is unavoidable here. Rare,
      // admin-only, and not on any hot path.
      qc.invalidateQueries({ queryKey: projectKeys.tasksRoot });
    },
  });
}
