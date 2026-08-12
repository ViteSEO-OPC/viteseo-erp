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

export function useArchivedWorkspaces(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...archiveKeys.workspaces, params ?? {}],
    queryFn: () => getArchivedWorkspaces(params),
  });
}

export function useRestoreWorkspace() {
  const qc = useQueryClient();
  return useMutation({
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
  });
}

export function useRestoreProject() {
  const qc = useQueryClient();
  return useMutation({
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
  });
}

export function useRestoreTask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => restoreTask(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: archiveKeys.tasks });
      // Restored task reappears on its project board.
      qc.invalidateQueries({ queryKey: ["projects", "projects"] });
    },
  });
}

export function useArchivedSubtasks(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...archiveKeys.subtasks, params ?? {}],
    queryFn: () => getArchivedSubtasks(params),
  });
}

export function useRestoreSubtask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => restoreSubtask(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: archiveKeys.subtasks });
      // Restored subtask reappears under its parent task.
      qc.invalidateQueries({ queryKey: ["projects", "tasks"] });
    },
  });
}
