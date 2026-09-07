import type { TeamUser, TeamUserStatus } from "@/modules/crm/features/settings/types/user-types";

type UserApi = {
  id: number | string;
  name: string;
  email: string;
  role: string | null;
  status: TeamUserStatus;
  last_login_at: string | null;
  created_at: string | null;
  avatar_url?: string | null;
  /** Only present on the list query, which selects it. */
  lead_count?: number | null;
};

/** Map the API UserResource shape to the frontend TeamUser. Exported for reuse in mutations. */
export function mapUser(api: UserApi): TeamUser {
  return {
    id: String(api.id),
    name: api.name,
    email: api.email,
    role: api.role,
    status: api.status,
    leadCount: typeof api.lead_count === "number" ? api.lead_count : null,
    avatarUrl: api.avatar_url ?? null,
    lastLoginAt: api.last_login_at,
    createdAt: api.created_at,
  };
}

/**
 * There is deliberately NO list hook here any more.
 *
 * `GET /users` lists by SPATIE ROLE, so an org staffed on its own custom roles —
 * whose members hold direct permissions and no role at all — came back EMPTY, and
 * the team screen showed a blank table with no explanation. The members list now
 * reads `GET /crm/team`, which counts org MEMBERSHIP (see `useTeamAccounts`).
 *
 * `mapUser` stays: the write endpoints under /users still answer with a
 * UserResource, and their results are read back through it.
 */
