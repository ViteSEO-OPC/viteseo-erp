"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  addProjectMember,
  createProject,
  deleteProject,
  getProject,
  getProjects,
  removeProjectMember,
  updateProject,
} from "@/modules/projects/api/projects";
import { projectKeys } from "@/modules/projects/lib/query-keys";

export function useProjects(workspaceId?: string) {
  return useQuery({
    queryKey: projectKeys.projects(workspaceId),
    queryFn: () => getProjects(workspaceId),
  });
}

export function useProject(id: string) {
  return useQuery({
    queryKey: projectKeys.project(id),
    queryFn: () => getProject(id),
    enabled: !!id,
  });
}

export function useCreateProject() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createProject,
    onSuccess: () =>
      qc.invalidateQueries({ queryKey: ["projects", "projects"] }),
  });
}

export function useUpdateProject() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: Parameters<typeof updateProject>[1];
    }) => updateProject(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: ["projects", "projects"] });
      qc.invalidateQueries({ queryKey: projectKeys.project(id) });
    },
  });
}

export function useDeleteProject() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => deleteProject(id),
    onSuccess: () =>
      qc.invalidateQueries({ queryKey: ["projects", "projects"] }),
  });
}

export function useProjectMembers(projectId: string) {
  const qc = useQueryClient();
  const invalidate = () =>
    qc.invalidateQueries({ queryKey: projectKeys.project(projectId) });
  const add = useMutation({
    mutationFn: (userId: number) =>
      addProjectMember(projectId, userId),
    onSuccess: invalidate,
  });
  const remove = useMutation({
    mutationFn: (userId: number) =>
      removeProjectMember(projectId, userId),
    onSuccess: invalidate,
  });
  return { add, remove };
}
