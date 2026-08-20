"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { CentralUser } from "@/modules/crm/features/settings/types/user-types";

type LookupApi = {
  id: number | string;
  name: string;
  email: string;
  roles: string[];
  systems: string[];
  crm_role: string | null;
};

/**
 * Email-first Create Account lookup.
 *
 * The platform shares one account per person across TeamOra, Zerem and Sprintly, so "create an
 * account" is the wrong default: most people the admin types in already HAVE an account. This asks
 * the API which case we are in before the form commits to a shape.
 *
 * `email` is the already-debounced/blurred address, or "" to stay idle. Exact match only — the
 * endpoint is a confirmation step, not a searchable directory.
 */
export function useUserLookup(email: string) {
  const query = useQuery({
    queryKey: queryKeys.users.lookup(email),
    enabled: email !== "",
    // The answer changes only when someone is added to the platform; keep it cached for the life of
    // the modal so tabbing back and forth does not re-query.
    staleTime: 60_000,
    retry: false,
    queryFn: async (): Promise<CentralUser | null> => {
      const res = await apiClient.get("/users/lookup", { params: { email } });
      const data = res.data.data as LookupApi | null;
      if (!data) return null;

      return {
        id: String(data.id),
        name: data.name,
        email: data.email,
        roles: data.roles ?? [],
        systems: data.systems ?? [],
        crmRole: data.crm_role,
      };
    },
  });

  return {
    /** The existing centralized user, or null once we know the email is new. */
    existingUser: query.data ?? null,
    /** True only after a completed lookup that found nobody. */
    isNewEmail: query.isSuccess && query.data === null,
    isChecking: query.isFetching,
    isError: query.isError,
  };
}
