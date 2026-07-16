"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";

export type BdUser = { id: string; name: string };

type UserApi = {
  id: number | string;
  name: string;
  role: string | null;
  roles?: string[];
  status: string;
};

/**
 * Active Business Development Manager accounts, for owner pickers (assign lead / import).
 * Reuses the Team Accounts endpoint (GET /users) and filters to active BDs — Super Admin's
 * /users returns every role, so the client filter guarantees BD-only regardless of caller.
 */
/** `enabled` guards the /users call for callers without users.view (e.g. a BD in the import/create UI). */
export function useBdUsers(enabled = true) {
  const query = useQuery({
    queryKey: queryKeys.users.list({ role: "crm_bd", status: "active" }),
    enabled,
    queryFn: async (): Promise<BdUser[]> => {
      const res = await apiClient.get("/users");
      // A user is a BD if they hold the crm_bd role. Check the full roles array (not the
      // single `role` field) so platform users with multiple per-system roles still match.
      return (res.data.data as UserApi[])
        .filter(
          (u) =>
            (u.roles?.includes("crm_bd") ?? u.role === "crm_bd") &&
            u.status === "active",
        )
        .map((u) => ({ id: String(u.id), name: u.name }));
    },
  });

  return {
    bdUsers: query.data ?? [],
    isLoading: query.isLoading,
    isError: query.isError,
  };
}
