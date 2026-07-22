"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { mapUser } from "@/modules/crm/features/settings/hooks/use-users-data";
import type {
  CreatedUser,
  CreateUserPayload,
  TeamUser,
  UpdateUserPayload,
} from "@/modules/crm/features/settings/types/user-types";

/**
 * Create a Team Account. Returns the created user + the one-time temporary password
 * (shown once in the success card). Success toast is intentionally omitted — the
 * "Account created" card is the confirmation.
 */
export function useCreateUser() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (payload: CreateUserPayload): Promise<CreatedUser> => {
      const res = await apiClient.post("/users", payload);
      return {
        user: mapUser(res.data.data),
        temporaryPassword: res.data.temporary_password as string,
      };
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.users.lists() });
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to create account"));
    },
  });
}

/** Update a Team Account (name / role / status). Caller supplies the success toast. */
export function useUpdateUser() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({
      id,
      payload,
    }: {
      id: string;
      payload: UpdateUserPayload;
    }): Promise<TeamUser> => {
      const res = await apiClient.patch(`/users/${id}`, payload);
      return mapUser(res.data.data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.users.lists() });
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to update account"));
    },
  });
}

/** Delete a Team Account (Super Admin only — enforced by the backend policy). */
export function useDeleteUser() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => apiClient.delete(`/users/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.users.lists() });
      toast.success("Account deleted");
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to delete account"));
    },
  });
}
