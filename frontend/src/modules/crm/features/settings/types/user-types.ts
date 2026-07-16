export type TeamUserStatus = "active" | "inactive";

/** A CRM user account as shown in Team Accounts (mapped from the API UserResource). */
export type TeamUser = {
  id: string;
  name: string;
  email: string;
  /** Primary role slug (e.g. "crm_bd"), or null. */
  role: string | null;
  status: TeamUserStatus;
  lastLoginAt: string | null;
  createdAt: string | null;
};

export type CreateUserPayload = {
  name: string;
  email: string;
  role: string;
  status?: TeamUserStatus;
  /** Optional admin-supplied temp password; server generates one when omitted. */
  password?: string;
};

export type UpdateUserPayload = {
  name?: string;
  role?: string;
  status?: TeamUserStatus;
};

/** POST /users response: the created user plus the one-time temporary password. */
export type CreatedUser = {
  user: TeamUser;
  temporaryPassword: string;
};
