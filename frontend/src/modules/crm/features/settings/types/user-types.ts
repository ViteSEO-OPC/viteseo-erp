export type TeamUserStatus = "active" | "inactive";

/** A CRM user account as shown in Team Accounts (mapped from the API UserResource). */
export type TeamUser = {
  id: string;
  name: string;
  /** Shared avatar source (signed link from `users.avatar`); null → render initials. */
  avatarUrl: string | null;
  email: string;
  /** Primary role slug (e.g. "crm_bd"), or null. */
  role: string | null;
  status: TeamUserStatus;
  lastLoginAt: string | null;
  createdAt: string | null;
};

/**
 * A centralized Vite SEO account discovered by exact email (GET /users/lookup).
 *
 * Distinct from {@link TeamUser}: this is a platform identity that may have no CRM access at all —
 * a TeamOra/Sprintly colleague the admin is about to grant Zerem access to.
 */
export type CentralUser = {
  id: string;
  name: string;
  email: string;
  /** Every platform role the account holds (hr_*, crm_*, pm_*). */
  roles: string[];
  /** Product names of the systems they can already reach, e.g. ["TeamOra", "Sprintly"]. */
  systems: string[];
  /** Their CRM role, or null when they cannot reach Zerem yet. */
  crmRole: string | null;
};

/**
 * POST /users serves both shapes. `name`/`password` are sent ONLY when creating a new account —
 * for an existing centralized user the API rejects them outright, because granting CRM access must
 * not be able to rename the person or touch their password.
 */
export type CreateUserPayload = {
  email: string;
  role: string;
  name?: string;
  status?: TeamUserStatus;
  /** Optional admin-supplied temp password; server generates one when omitted. New accounts only. */
  password?: string;
};

/**
 * PATCH /users/{id}. `role` is sent ONLY when the admin changed it — omitting it leaves the role
 * pivot untouched, so a rename or suspend never rewrites roles. `null` is the explicit "no CRM
 * access" choice; it removes CRM roles only and never touches HRIS/Projects access.
 */
export type UpdateUserPayload = {
  name?: string;
  role?: string | null;
  status?: TeamUserStatus;
};

/**
 * POST /users response. Two shapes behind one endpoint:
 *
 *  - `granted: false` — a new account was created; `temporaryPassword` carries the one-time
 *    credential to hand over.
 *  - `granted: true`  — an existing centralized user was given CRM access. There is no
 *    `temporaryPassword`, by design: they keep the password they already sign in with.
 */
export type CreatedUser = {
  user: TeamUser;
  granted: boolean;
  temporaryPassword?: string;
};
