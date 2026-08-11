"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  addGroupMember,
  createGroup,
  deleteGroup,
  getGroup,
  getGroups,
  removeGroupMember,
  updateGroup,
} from "@/modules/projects/api/groups";
import { projectKeys } from "@/modules/projects/lib/query-keys";

export function useGroups(projectId?: string) {
  return useQuery({
    queryKey: projectKeys.groups(projectId),
    queryFn: () => getGroups(projectId),
  });
}

export function useGroup(id: string) {
  return useQuery({
    queryKey: projectKeys.group(id),
    queryFn: () => getGroup(id),
    enabled: !!id,
  });
}

export function useCreateGroup() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createGroup,
    onSuccess: () => qc.invalidateQueries({ queryKey: ["projects", "groups"] }),
  });
}

export function useUpdateGroup() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: { name?: string; color?: string; leader_id?: number };
    }) => updateGroup(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: ["projects", "groups"] });
      qc.invalidateQueries({ queryKey: projectKeys.group(id) });
    },
  });
}

export function useDeleteGroup() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => deleteGroup(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["projects", "groups"] }),
  });
}

export function useGroupMembers(groupId: string) {
  const qc = useQueryClient();
  const invalidate = () =>
    qc.invalidateQueries({ queryKey: projectKeys.group(groupId) });
  const add = useMutation({
    mutationFn: (userId: number) => addGroupMember(groupId, userId),
    onSuccess: invalidate,
  });
  const remove = useMutation({
    mutationFn: (userId: number) =>
      removeGroupMember(groupId, userId),
    onSuccess: invalidate,
  });
  return { add, remove };
}
