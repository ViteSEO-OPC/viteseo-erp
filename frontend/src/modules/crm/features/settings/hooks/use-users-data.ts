"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { TeamUser, TeamUserStatus } from "@/modules/crm/features/settings/types/user-types";

type UserApi = {
  id: number | string;
  name: string;
  email: string;
  role: string | null;
  status: TeamUserStatus;
  last_login_at: string | null;
  created_at: string | null;
};

/** Map the API UserResource shape to the frontend TeamUser. Exported for reuse in mutations. */
export function mapUser(api: UserApi): TeamUser {
  return {
    id: String(api.id),
    name: api.name,
    email: api.email,
    role: api.role,
    status: api.status,
    lastLoginAt: api.last_login_at,
    createdAt: api.created_at,
  };
}

/**
 * Team Accounts list. The backend scopes results by role (Admin → BD accounts only,
 * Super Admin → everyone), so no client-side filtering is needed.
 */
export function useUsers() {
  const query = useQuery({
    queryKey: queryKeys.users.list({}),
    queryFn: async () => {
      const res = await apiClient.get("/users");
      const items = (res.data.data as UserApi[]).map(mapUser);
      const total: number = res.data.meta?.total ?? items.length;
      return { users: items, total };
    },
  });

  return {
    users: query.data?.users ?? [],
    total: query.data?.total ?? 0,
    isLoading: query.isLoading,
    isError: query.isError,
    retry: query.refetch,
  };
}
