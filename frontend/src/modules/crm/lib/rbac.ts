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
 * Approved matrix: Business Development Manager → Dashboard/Leads/Outreach/Activity Logs;
 * Admin (CRM Manager) additionally → Reports/Settings; Super Admin → everything.
 * Planner is BD-exclusive (a personal workspace) — see its entry.
 */
export type PageAccess = RoleAccessRule & { href: string };

export const PAGE_ACCESS: PageAccess[] = [
  // /dashboard is a single route for all roles; its content is role-dependent (BD Dashboard
  // vs Management Dashboard) — chosen in the page, not by routing.
  { href: "/zerem/dashboard", minRole: "crm_bd" },
  { href: "/zerem/leads", minRole: "crm_bd" },
  // Nearby Clients + Outreach are Business Development workspaces (🔒 PO decision 2026-07-24, RBAC R-3).
  // Admin (CRM Manager) is limited to Dashboard · Leads · Reports & Performance · Activity Logs ·
  // Settings, so these use an explicit allow-list rather than `minRole` — which is inherit-upward and
  // therefore handed them to Admin. Super Admin is listed explicitly because it has full system access
  // and must NOT be excluded by the allow-list.
  { href: "/zerem/nearby-clients", allowedRoles: ["crm_bd", "super_admin"] },
  { href: "/zerem/follow-ups", allowedRoles: ["crm_bd", "super_admin"] },
  // Planner History — completed Planner work with no 7-day window. A read-only REPORTING surface, not
  // a workspace, so unlike the Planner itself it is open to Admin/Super Admin (who review BD output)
  // as well as the BD who owns the work. It is deliberately its own route rather than a mode inside
  // /zerem/planner: that keeps Calendar and Board unreachable for Admin/SA at the ROUTE level (the
  // RoleGuard bounces them off /zerem/planner) instead of merely hiding two tabs in the UI.
  //
  // MUST stay listed above /zerem/planner for readability, though correctness no longer depends on it —
  // accessRuleForPath() resolves the LONGEST matching prefix, so this specific rule wins over the
  // BD-only parent regardless of array order.
  { href: "/zerem/planner/history", allowedRoles: ["crm_bd", "crm_admin", "super_admin"] },
  // Planner (personal BD workspace) — first consumer of the `allowedRoles` allow-list: visible
  // ONLY to Business Development Managers; hidden from Admin/Super Admin (who outrank but do not
  // own a Planner). The backend `calendar.*` permissions + PlannerEvent ownership are unchanged.
  // 🔒 FROZEN — Admin/Super Admin must NOT be added here; they get History only, via the route above.
  { href: "/zerem/planner", allowedRoles: ["crm_bd"] },
  // Temporary backward-compat alias — `/calendar` redirects to `/planner`; gated identically so
  // Admin/SA are bounced before the redirect. Remove with the alias page.
  { href: "/zerem/calendar", allowedRoles: ["crm_bd"] },
  // My Performance — BD-exclusive personal surface. Admin/Super Admin use Reports & Performance
  // (/zerem/reports), which shows the same widgets with an All-Reports / per-BD scope selector.
  { href: "/zerem/my-performance", allowedRoles: ["crm_bd"] },
  { href: "/zerem/activity-logs", minRole: "crm_bd" },
  // Notification History — a personal surface, like the bell it is reached from: every
  // CRM role has notifications, and the feed is scoped to the caller server-side, so
  // `minRole` (inherit-upward) rather than an allow-list. It is reachable only from the
  // dropdown's "View all", NOT the sidebar — the bell stays the single entry point.
  { href: "/zerem/notifications", minRole: "crm_bd" },
  // BD self-service profile ("Settings" in the BD sidebar) — role-exclusive, like Planner: visible
  // ONLY to Business Development Managers. Admin/Super Admin have the separate admin Settings module
  // at /settings. Self-service edits own name + phone via PATCH /me (no admin user management).
  { href: "/zerem/profile", allowedRoles: ["crm_bd"] },
  { href: "/zerem/reports", minRole: "crm_admin" },
  { href: "/zerem/settings", minRole: "crm_admin" },
];

/**
 * The access rule for the page a path belongs to (prefix match), or null if ungated.
 *
 * Resolves the LONGEST matching prefix, not the first one declared. Nested routes may carry a rule
 * that differs from their parent's (e.g. `/zerem/planner` is BD-only while `/zerem/planner/history`
 * also admits Admin/Super Admin); a first-match scan would silently apply the parent's rule to every
 * child and make the more specific entry dead code depending on array order.
 */
export function accessRuleForPath(pathname: string): PageAccess | null {
  let best: PageAccess | null = null;
  for (const p of PAGE_ACCESS) {
    if (pathname !== p.href && !pathname.startsWith(p.href + "/")) continue;
    if (best === null || p.href.length > best.href.length) {
      best = p;
    }
  }
  return best;
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
