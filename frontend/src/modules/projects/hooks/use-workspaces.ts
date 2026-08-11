"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  createWorkspace,
  deleteWorkspace,
  getWorkspace,
  getWorkspaces,
  updateWorkspace,
} from "@/modules/projects/api/workspaces";
import { projectKeys } from "@/modules/projects/lib/query-keys";

export function useWorkspaces(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...projectKeys.workspaces, params ?? {}],
    queryFn: () => getWorkspaces(params),
  });
}

export function useWorkspace(id: string) {
  return useQuery({
    queryKey: projectKeys.workspace(id),
    queryFn: () => getWorkspace(id),
    enabled: !!id,
  });
}

export function useCreateWorkspace() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createWorkspace,
    onSuccess: () => qc.invalidateQueries({ queryKey: projectKeys.workspaces }),
  });
}

export function useUpdateWorkspace() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: { name?: string; description?: string | null };
    }) => updateWorkspace(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: projectKeys.workspaces });
      qc.invalidateQueries({ queryKey: projectKeys.workspace(id) });
    },
  });
}

export function useDeleteWorkspace() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => deleteWorkspace(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.workspaces });
      // Projects roll up under workspaces; refresh their lists too.
      qc.invalidateQueries({ queryKey: ["projects", "projects"] });
    },
  });
}
