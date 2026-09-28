// Cross-module access logic for the "Vite SEO Systems" launcher.
//
// A user can enter a module when they hold ANY permission in that module's
// namespace (this auto-respects the seeder's role→permission mapping). HRIS is
// additionally reachable by anyone with the self-service `hr_employee` role.
//
// Pure + framework-free so it can be reused by BOTH the launcher page (which
// renders the accessible cards) and the login redirect (which decides where to
// land). Keep it dependency-light — only the lucide icon type is imported.

import { Users, Handshake, FolderKanban, LayoutTemplate, Calculator, type LucideIcon } from "lucide-react";

export type ModuleKey = "hris" | "crm" | "projects" | "accounting" | "kiosk";

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
  /**
   * Hide the card entirely when it is not accessible, instead of showing it locked.
   *
   * A locked card is an ADVERT: it says "you could have this, ask us". That is right
   * for a product a client has not availed yet and wrong for anything that is not
   * for sale, because it offers something they can never buy and names an internal
   * tool while doing it.
   */
  hideWhenLocked?: boolean;
}

/** Roles that grant HR-management access (the full HR portal at /teamora). */
const HR_MANAGER_ROLES = ["hr_officer", "super_admin"];

/** Launcher module key → the org-entitlement feature key it requires. */
const MODULE_FEATURE_KEY: Record<ModuleKey, string> = {
  hris: "hr",
  crm: "crm",
  projects: "pms",
  accounting: "accounting",
  // Kiosk is an INTERNAL tool, not a sold entitlement — access is permission-only
  // (see the tile below), so this mapping is unused for kiosk but kept for the
  // Record's completeness.
  kiosk: "kiosk",
};

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
 * Every module tile — ALWAYS the full set, in stable order (HRIS → CRM →
 * Projects → Accounting → Kiosk) — each flagged `accessible` for the current
 * user. The launcher renders the sold ones (locking those the user can't enter)
 * and hides the internal tools; callers that only want the reachable ones use
 * `getAccessibleModules` below.
 */
export function getModuleTiles(
  roles: string[],
  permissions: string[],
  enabledModules?: string[],
): ModuleTile[] {
  // HR-management access (role or any hr.employees.* perm) → the full HR portal;
  // plain employees get their self-service overview at the root.
  const isHrManager =
    roles.some((r) => HR_MANAGER_ROLES.includes(r)) ||
    hasNamespace(permissions, "hr.employees");
  const canHris = hasNamespace(permissions, "hr") || roles.includes("hr_employee");

  // A tile is reachable only when the user's permissions grant it AND (when the
  // org's entitlements are known) the org has availed that module. While
  // entitlements are still loading (undefined), fall back to permission-only.
  const entitled = (key: ModuleKey, permitted: boolean): boolean =>
    enabledModules === undefined
      ? permitted
      : permitted && enabledModules.includes(MODULE_FEATURE_KEY[key]);

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
      accessible: entitled("hris", canHris),
    },
    {
      key: "crm",
      title: "Zerem",
      subtitle: "CRM",
      description: "Leads, follow-ups, clients & sales performance.",
      icon: Handshake,
      href: "/zerem/dashboard",
      accessible: entitled("crm", hasNamespace(permissions, "crm")),
    },
    {
      key: "projects",
      title: "Sprintly",
      subtitle: "Projects",
      description: "Workspaces, boards, tasks & team planning.",
      icon: FolderKanban,
      href: "/sprintly",
      accessible: entitled("projects", hasNamespace(permissions, "projects")),
    },
    {
      key: "accounting",
      // No product name yet, unlike its three siblings — see brandLabel in nav.ts.
      title: "Accounting",
      subtitle: "Books",
      description: "Ledger, invoices, bills, BIR reports & budgets.",
      icon: Calculator,
      href: "/accounting/overview",
      accessible: entitled("accounting", hasNamespace(permissions, "accounting")),
    },
    {
      key: "kiosk",
      title: "Kiosk",
      subtitle: "Web Configurator",
      description: "Build a client's website mockup — pick, preview & capture the lead.",
      icon: LayoutTemplate,
      href: "/kiosk/overview",
      // Internal tool: permission-only, NOT gated by an org entitlement -- and not
      // sold, so a client must not even see it locked. It is ViteSEO's own lead-gen
      // configurator; a client org has no kiosk permission and no way to get one.
      accessible: hasNamespace(permissions, "kiosk"),
      hideWhenLocked: true,
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
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  _roles: string[],
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  _permissions: string[],
): string {
  return "/";
}
