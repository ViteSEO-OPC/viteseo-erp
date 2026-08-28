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

type UserListResponse = {
  data: UserApi[];
  meta: { last_page: number };
};

// Safety cap, same shape as the one in use-export-leads.ts. 50 pages is 750 accounts — far beyond
// any plausible roster for a single company, so hitting it means something is wrong rather than
// large. Deliberately generous: capping too low would silently drop BDs, which is the exact bug
// this paging fixes.
const MAX_PAGES = 50;

/**
 * Active Business Development Manager accounts, for owner pickers (assign lead / import).
 * Reuses the Team Accounts endpoint (GET /users) and filters to active BDs — Super Admin's
 * /users returns every role, so the client filter guarantees BD-only regardless of caller.
 *
 * EVERY page is read, not just the first, because that same "returns every role" is a trap for a
 * Super Admin. UserController@index only narrows to crm_bd when the caller is NOT a super_admin,
 * then orders `latest('id')` and paginates 15. So an Admin gets a short BD-only list that always
 * fits one page, while a Super Admin gets the whole roster newest-first — and the BD accounts, which
 * are usually the OLDEST rows, fall off page 1 the moment the platform has more than 15 users. The
 * client filter then matched nothing and every owner picker in the CRM came back empty for the one
 * role with the most authority: no BD tabs on Leads, no per-BD Reports scope, and no assignable BD
 * in the assign, bulk-reassign, import, create-lead or archive surfaces.
 *
 * Paging is the frontend-only fix (the endpoint ignores `per_page` and `role`), and it follows the
 * same do/while + MAX_PAGES shape as {@link useExportLeads}. The narrower answer is a server-side
 * role filter; this deliberately does not change the endpoint, which Team Accounts also uses.
 *
 * The query key, return shape and caching are unchanged, so all consumers keep working as-is.
 */
/** `enabled` guards the /users call for callers without users.view (e.g. a BD in the import/create UI). */
export function useBdUsers(enabled = true) {
  const query = useQuery({
    queryKey: queryKeys.users.list({ role: "crm_bd", status: "active" }),
    enabled,
    queryFn: async (): Promise<BdUser[]> => {
      const rows: UserApi[] = [];
      let page = 1;
      let lastPage = 1;

      do {
        const res = await apiClient.get<UserListResponse>("/users", { params: { page } });
        rows.push(...res.data.data);
        lastPage = res.data.meta?.last_page ?? 1;
        page += 1;
      } while (page <= lastPage && page <= MAX_PAGES);

      // A user is a BD if they hold the crm_bd role. Check the full roles array (not the
      // single `role` field) so platform users with multiple per-system roles still match.
      // `status` here is CRM access (UserResource computes it from CrmAccessService), not the
      // shared users.status column, so a suspended BD drops out of every picker automatically.
      return rows
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
