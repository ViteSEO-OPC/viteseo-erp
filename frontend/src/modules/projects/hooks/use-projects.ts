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
import { notifyMutationError } from "@/modules/projects/lib/api-error";
import { STALE } from "@/modules/projects/lib/query-freshness";

export function useProjects(workspaceId?: string) {
  return useQuery({
    queryKey: projectKeys.projects(workspaceId),
    queryFn: () => getProjects(workspaceId),
    staleTime: STALE.long,
  });
}

export function useProject(id: string) {
  return useQuery({
    queryKey: projectKeys.project(id),
    queryFn: () => getProject(id),
    enabled: !!id,
    staleTime: STALE.standard,
  });
}

export function useCreateProject() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't create that project"),
    mutationFn: createProject,
    onSuccess: () =>
      qc.invalidateQueries({ queryKey: projectKeys.projectsRoot }),
  });
}

export function useUpdateProject() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't save that project"),
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: Parameters<typeof updateProject>[1];
    }) => updateProject(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: projectKeys.projectsRoot });
      qc.invalidateQueries({ queryKey: projectKeys.project(id) });
    },
  });
}

export function useDeleteProject() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't delete that project"),
    mutationFn: (id: string) => deleteProject(id),
    onSuccess: () =>
      qc.invalidateQueries({ queryKey: projectKeys.projectsRoot }),
  });
}

export function useProjectMembers(projectId: string) {
  const qc = useQueryClient();
  const invalidate = () =>
    qc.invalidateQueries({ queryKey: projectKeys.project(projectId) });
  const add = useMutation({
    onError: notifyMutationError("Couldn't add that member"),
    mutationFn: (userId: number) => addProjectMember(projectId, userId),
    onSuccess: invalidate,
  });
  const remove = useMutation({
    mutationFn: (userId: number) => removeProjectMember(projectId, userId),
    onSuccess: invalidate,
  });
  return { add, remove };
}
