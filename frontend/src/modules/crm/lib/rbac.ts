// Frontend RBAC foundation. Page/module visibility is role-based; in-page actions stay
// permission-based (usePermissions().can). Mirrors the backend's approved model:
// Super Admin (system owner) → Admin (CRM Manager) → Business Development Manager.

export type Role =
  | "super_admin"
  | "crm_admin"
  | "crm_bd";

export const ROLE_RANK: Record<Role, number> = {
  "crm_bd": 1,
  crm_admin: 2,
  "super_admin": 3,
};

export const ROLE_LABELS: Record<Role, string> = {
  "super_admin": "Super Admin",
  crm_admin: "Admin",
  "crm_bd": "Business Development Manager",
};

/** Short label used in compact chips (e.g. the Team Accounts role column). */
export const ROLE_SHORT_LABELS: Record<Role, string> = {
  "super_admin": "Super Admin",
  crm_admin: "Admin",
  "crm_bd": "BD",
};

export function isRole(value: string): value is Role {
  return value in ROLE_RANK;
}

export function hasRoleName(roles: readonly string[] | undefined, role: Role): boolean {
  return (roles ?? []).includes(role);
}

/** The highest-ranked role the user holds (the app assigns exactly one). */
export function highestRole(roles: readonly string[] | undefined): Role | null {
  let best: Role | null = null;
  for (const r of roles ?? []) {
    if (isRole(r) && (best === null || ROLE_RANK[r] > ROLE_RANK[best])) {
      best = r;
    }
  }
  return best;
}

export function roleRank(roles: readonly string[] | undefined): number {
  const r = highestRole(roles);
  return r ? ROLE_RANK[r] : 0;
}

export function hasMinRole(roles: readonly string[] | undefined, min: Role): boolean {
  return roleRank(roles) >= ROLE_RANK[min];
}

/**
 * A generic, reusable role-based access rule. Two strategies (pick one):
 *
 * - `allowedRoles` — an **exact allow-list**: only the listed roles may access, and higher roles
 *   do **NOT** inherit. Use for role-exclusive surfaces (e.g. a personal workspace that must be
 *   hidden from Admin/Super Admin even though they outrank the owner).
 * - `minRole` — the **lowest** role that may access; higher roles **inherit** (the default model).
 *
 * `allowedRoles` takes precedence when both are present. A rule with neither is open to any
 * authenticated user. This rule shape is shared by the sidebar and the route guard, and any
 * future module can adopt it without new gating logic.
 */
export type RoleAccessRule = {
  minRole?: Role;
  allowedRoles?: readonly Role[];
};

/** Whether a user's roles satisfy a {@link RoleAccessRule}. The single source of truth for role
 *  visibility — used by both the sidebar filter and the route `RoleGuard`. */
export function satisfiesRoleAccess(
  roles: readonly string[] | undefined,
  rule: RoleAccessRule,
): boolean {
  if (rule.allowedRoles && rule.allowedRoles.length > 0) {
    return (roles ?? []).some((r) => isRole(r) && rule.allowedRoles!.includes(r));
  }
  if (rule.minRole) {
    return hasMinRole(roles, rule.minRole);
  }
  return true;
}

/**
 * Roles a user may assign when creating/editing accounts — mirrors the backend
 * `User::assignableRoles()`. Super Admin is never assignable through the app.
 */
export function assignableRoles(roles: readonly string[] | undefined): Role[] {
  if (hasRoleName(roles, "super_admin")) {
    return ["crm_admin", "crm_bd"];
  }
  if (hasRoleName(roles, "crm_admin")) {
    return ["crm_bd"];
  }
  return [];
}

/**
 * Page/module visibility — the single source of truth for both the sidebar and the
 * RoleGuard. Each entry is a {@link RoleAccessRule}: use `minRole` for the default
 * inherit-upward model, or `allowedRoles` for a role-exclusive surface.
 * Approved matrix: BD Manager → Dashboard/Leads/Outreach/Activity Logs;
 * Admin (CRM Manager) additionally → Reports/Settings; Super Admin → everything.
 * Planner is BD-exclusive (a personal workspace) — see its entry.
 */
export type PageAccess = RoleAccessRule & { href: string };

export const PAGE_ACCESS: PageAccess[] = [
  // /dashboard is a single route for all roles; its content is role-dependent (BD Dashboard
  // vs Management Dashboard) — chosen in the page, not by routing.
  { href: "/zerem/dashboard", minRole: "crm_bd" },
  { href: "/zerem/leads", minRole: "crm_bd" },
  { href: "/zerem/nearby-clients", minRole: "crm_bd" },
  { href: "/zerem/follow-ups", minRole: "crm_bd" },
  // Planner (personal BD workspace) — first consumer of the `allowedRoles` allow-list: visible
  // ONLY to Business Development Managers; hidden from Admin/Super Admin (who outrank but do not
  // own a Planner). The backend `calendar.*` permissions + PlannerEvent ownership are unchanged.
  { href: "/zerem/planner", allowedRoles: ["crm_bd"] },
  // Temporary backward-compat alias — `/calendar` redirects to `/planner`; gated identically so
  // Admin/SA are bounced before the redirect. Remove with the alias page.
  { href: "/zerem/calendar", allowedRoles: ["crm_bd"] },
  // My Performance — BD-facing personal surface; content is ownership-scoped server-side.
  { href: "/zerem/my-performance", minRole: "crm_bd" },
  { href: "/zerem/activity-logs", minRole: "crm_bd" },
  { href: "/zerem/reports", minRole: "crm_admin" },
  { href: "/zerem/settings", minRole: "crm_admin" },
];

/** The access rule for the page a path belongs to (prefix match), or null if ungated. */
export function accessRuleForPath(pathname: string): PageAccess | null {
  return (
    PAGE_ACCESS.find(
      (p) => pathname === p.href || pathname.startsWith(p.href + "/"),
    ) ?? null
  );
}

/** The minRole for the page a path belongs to, or null if ungated / allow-list-based. */
export function minRoleForPath(pathname: string): Role | null {
  return accessRuleForPath(pathname)?.minRole ?? null;
}

/** Whether the given roles may access a path. Ungated paths are always allowed. Honors both the
 *  `minRole` (inherit-upward) and `allowedRoles` (exact allow-list) strategies. */
export function canAccessPath(
  roles: readonly string[] | undefined,
  pathname: string,
): boolean {
  const rule = accessRuleForPath(pathname);
  return rule ? satisfiesRoleAccess(roles, rule) : true;
}
