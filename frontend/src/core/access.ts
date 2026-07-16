// Cross-module access logic for the "Vite SEO Systems" launcher.
//
// A user can enter a module when they hold ANY permission in that module's
// namespace (this auto-respects the seeder's role→permission mapping). HRIS is
// additionally reachable by anyone with the self-service `hr_employee` role.
//
// Pure + framework-free so it can be reused by BOTH the launcher page (which
// renders the accessible cards) and the login redirect (which decides where to
// land). Keep it dependency-light — only the lucide icon type is imported.

import { Users, Handshake, FolderKanban, type LucideIcon } from "lucide-react";

export type ModuleKey = "hris" | "crm" | "projects";

export interface AccessibleModule {
  key: ModuleKey;
  /** Friendly product name shown as the card title (e.g. "TeamOra"). */
  title: string;
  /** The system's function, shown as the subtitle (e.g. "HRIS"). */
  subtitle: string;
  /** One-line description of what the module is for. */
  description: string;
  icon: LucideIcon;
  /** Where clicking the card (or a single-module login) sends the user. */
  href: string;
}

/** Roles that grant HR-management access (the full HR portal at /teamora). */
const HR_MANAGER_ROLES = ["hr_officer", "super_admin"];

/** True when any permission falls inside the given `namespace.` prefix. */
function hasNamespace(permissions: string[], namespace: string): boolean {
  const prefix = `${namespace}.`;
  return permissions.some((p) => p.startsWith(prefix));
}

/** A module tile plus whether the current user may actually enter it. */
export interface ModuleTile extends AccessibleModule {
  accessible: boolean;
}

/**
 * Every module tile — ALWAYS all three, in stable order (HRIS → CRM → Projects) —
 * each flagged `accessible` for the current user. The launcher renders all three
 * (locking the ones the user can't enter); callers that only want the reachable
 * ones use `getAccessibleModules` below.
 */
export function getModuleTiles(
  roles: string[],
  permissions: string[],
): ModuleTile[] {
  // HR-management access (role or any hr.employees.* perm) → the full HR portal;
  // plain employees get their self-service overview at the root.
  const isHrManager =
    roles.some((r) => HR_MANAGER_ROLES.includes(r)) ||
    hasNamespace(permissions, "hr.employees");
  const canHris = hasNamespace(permissions, "hr") || roles.includes("hr_employee");

  return [
    {
      key: "hris",
      title: "TeamOra",
      subtitle: "HRIS",
      description: isHrManager
        ? "People, attendance, payroll & company records."
        : "Your payslips, time off, claims & profile.",
      icon: Users,
      href: "/teamora",
      accessible: canHris,
    },
    {
      key: "crm",
      title: "Zerem",
      subtitle: "CRM",
      description: "Leads, follow-ups, clients & sales performance.",
      icon: Handshake,
      href: "/zerem/dashboard",
      accessible: hasNamespace(permissions, "crm"),
    },
    {
      key: "projects",
      title: "Sprintly",
      subtitle: "Projects",
      description: "Workspaces, boards, tasks & team planning.",
      icon: FolderKanban,
      href: "/sprintly",
      accessible: hasNamespace(permissions, "projects"),
    },
  ];
}

/**
 * The modules the user may actually enter (accessible tiles only) — used by the
 * login redirect and the "Switch app" visibility checks.
 */
export function getAccessibleModules(
  roles: string[],
  permissions: string[],
): AccessibleModule[] {
  return getModuleTiles(roles, permissions).filter((m) => m.accessible);
}

/**
 * Where a freshly authenticated user lands: ALWAYS the launcher at the site
 * root `/`, so everyone sees the full Vite SEO Systems suite (accessible
 * systems are clickable; the rest are shown locked) and picks one from there.
 *
 * Params kept for a stable call signature with the login page.
 */
export function resolveLandingHref(
  _roles: string[],
  _permissions: string[],
): string {
  return "/";
}
