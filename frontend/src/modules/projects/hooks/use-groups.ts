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
import { notifyMutationError } from "@/modules/projects/lib/api-error";
import { STALE } from "@/modules/projects/lib/query-freshness";

export function useGroups(projectId?: string) {
  return useQuery({
    queryKey: projectKeys.groups(projectId),
    queryFn: () => getGroups(projectId),
    staleTime: STALE.long,
  });
}

export function useGroup(id: string) {
  return useQuery({
    queryKey: projectKeys.group(id),
    queryFn: () => getGroup(id),
    enabled: !!id,
    staleTime: STALE.long,
  });
}

export function useCreateGroup() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't create that group"),
    mutationFn: createGroup,
    onSuccess: () => qc.invalidateQueries({ queryKey: projectKeys.groupsRoot }),
  });
}

export function useUpdateGroup() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't save that group"),
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: { name?: string; color?: string; leader_id?: number };
    }) => updateGroup(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: projectKeys.groupsRoot });
      qc.invalidateQueries({ queryKey: projectKeys.group(id) });
    },
  });
}

export function useDeleteGroup() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't delete that group"),
    mutationFn: (id: string) => deleteGroup(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: projectKeys.groupsRoot }),
  });
}

export function useGroupMembers(groupId: string) {
  const qc = useQueryClient();
  const invalidate = () =>
    qc.invalidateQueries({ queryKey: projectKeys.group(groupId) });
  const add = useMutation({
    onError: notifyMutationError("Couldn't add that member"),
    mutationFn: (userId: number) => addGroupMember(groupId, userId),
    onSuccess: invalidate,
  });
  const remove = useMutation({
    onError: notifyMutationError("Couldn't remove that member"),
    mutationFn: (userId: number) => removeGroupMember(groupId, userId),
    onSuccess: invalidate,
  });
  return { add, remove };
}
