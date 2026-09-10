"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { TEAM_ACCOUNTS_QUERY_KEY } from "@/core/team/use-team-accounts";
import { mapUser } from "@/modules/crm/features/settings/hooks/use-users-data";
import type {
  CreatedUser,
  CreateUserPayload,
  TeamUser,
  UpdateUserPayload,
} from "@/modules/crm/features/settings/types/user-types";

/**
 * Every write here goes to /users; the screen that shows the result LISTS from
 * /crm/team.
 *
 * That split is deliberate — /users cannot see a member holding one of the org's
 * own roles — but it means the cache these mutations change is not the cache the
 * member rows read. Each one therefore invalidates BOTH: the /users caches that
 * still feed the owner pickers, and the platform list the Team & Roles rows are
 * drawn from. Dropping the second is invisible in review and shows up as a row
 * that keeps its old name, role or status until the page is reloaded.
 */

/**
 * Create a Team Account, OR grant CRM access to an existing centralized user — one endpoint, the
 * branch chosen server-side by whether the email already resolves to an account.
 *
 * A create returns the one-time temporary password (shown once in the success card); a grant
 * deliberately returns none, because the person keeps the credentials they already sign in with.
 * Success toast is intentionally omitted — the success card is the confirmation.
 */
export function useCreateUser() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (payload: CreateUserPayload): Promise<CreatedUser> => {
      const res = await apiClient.post("/users", payload);
      return {
        user: mapUser(res.data.data),
        granted: Boolean(res.data.granted),
        temporaryPassword: (res.data.temporary_password as string | undefined) ?? undefined,
      };
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.users.lists() });
      queryClient.invalidateQueries({ queryKey: TEAM_ACCOUNTS_QUERY_KEY });
      // The granted user's CRM role changed, so any cached lookup for their address is stale.
      queryClient.invalidateQueries({ queryKey: [...queryKeys.users.all, "lookup"] });
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
      queryClient.invalidateQueries({ queryKey: TEAM_ACCOUNTS_QUERY_KEY });
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to update account"));
    },
  });
}

/**
 * Hand a departing BD's whole portfolio to one active BD.
 *
 * Fires ONE request; the backend runs the existing per-lead assignment logic for each lead, so the
 * `lead.assigned` audit trail and the Planner ownership transfer happen exactly as they do for a
 * single handover. Leads AND users are invalidated: the source row's count drops to zero and the
 * destination's rises.
 */
export function useReassignAllLeads() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ userId, toUserId }: { userId: string; toUserId: string }) => {
      const res = await apiClient.post(`/users/${userId}/reassign-leads`, { to_user_id: toUserId });
      return res.data.data as { leads_reassigned: number; standalone_events_transferred: number };
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.users.lists() });
      queryClient.invalidateQueries({ queryKey: TEAM_ACCOUNTS_QUERY_KEY });
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.all });
      toast.success(
        data.leads_reassigned === 0 && data.standalone_events_transferred === 0
          ? "Nothing to reassign"
          : `Reassigned ${data.leads_reassigned} lead${data.leads_reassigned === 1 ? "" : "s"}`,
      );
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to reassign leads"));
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
      queryClient.invalidateQueries({ queryKey: TEAM_ACCOUNTS_QUERY_KEY });
      toast.success("Account deleted");
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to delete account"));
    },
  });
}
