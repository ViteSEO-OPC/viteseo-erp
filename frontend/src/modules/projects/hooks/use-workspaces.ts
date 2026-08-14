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
import { notifyMutationError } from "@/modules/projects/lib/api-error";
import { STALE } from "@/modules/projects/lib/query-freshness";

export function useWorkspaces(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...projectKeys.workspaces, params ?? {}],
    queryFn: () => getWorkspaces(params),
    staleTime: STALE.long,
  });
}

export function useWorkspace(id: string) {
  return useQuery({
    queryKey: projectKeys.workspace(id),
    queryFn: () => getWorkspace(id),
    enabled: !!id,
    staleTime: STALE.long,
  });
}

export function useCreateWorkspace() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't create that workspace"),
    mutationFn: createWorkspace,
    onSuccess: () => qc.invalidateQueries({ queryKey: projectKeys.workspaces }),
  });
}

export function useUpdateWorkspace() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't save that workspace"),
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
    onError: notifyMutationError("Couldn't delete that workspace"),
    mutationFn: (id: string) => deleteWorkspace(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.workspaces });
      // Projects roll up under workspaces; refresh their lists too.
      qc.invalidateQueries({ queryKey: projectKeys.projectsRoot });
    },
  });
}
