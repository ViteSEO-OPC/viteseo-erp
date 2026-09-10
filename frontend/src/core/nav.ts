import {
  LayoutGrid,
  Users,
  UserPlus,
  Clock,
  Banknote,
  Receipt,
  Wallet,
  Heart,
  BarChart3,
  FileText,
  Building2,
  Network,
  Inbox,
  CalendarDays,
  ShieldAlert,
  Tag,
  ScrollText,
  CalendarRange,
  HelpCircle,
  Wand2,
  KeyRound,
  UserCog,
  Zap,
  Handshake,
  FolderKanban,
  LayoutTemplate,
  BookOpen,
  NotebookText,
  Scale,
  Landmark,
  TrendingUp,
  TrendingDown,
  BookText,
  ArrowLeftRight,
  Percent,
  RefreshCw,
  ShoppingCart,
  Waypoints,
  Calculator,
  type LucideIcon,
} from "lucide-react";

import type { Role } from "@/core/types/auth";

export type ShellVariant =
  | "hr"
  | "employee"
  | "kiosk"
  /** Sprintly (PMS) — nav built per render from live workspaces/projects. */
  | "projects"
  /** Zerem (CRM) — nav built per render from the viewer's CRM role. */
  | "crm"
  /** Accounting — the books, sold as its own system. */
  | "accounting";

export interface NavLink {
  label: string;
  href: string;
  icon: LucideIcon;
  /** Feature not built yet — the route renders a StubPage for now. */
  stub?: boolean;
  /** Org must have availed this entitlement key to see this item (see useFeatures). */
  feature?: string;
  /**
   * Opt-in SaaS upsell: when set with a `feature` the org HASN'T availed, the
   * item is shown LOCKED (not hidden) with an upsell hint, instead of being a
   * working link. Items without this behave exactly as before (non-breaking).
   */
  lockable?: boolean;
  /** Short tier/add-on label shown on the lock (e.g. "Pro", "Full"). */
  upsell?: string;
  /** Role required to see this link (e.g. Team = Finance Admin only). */
  requiresRole?: Role;
  /**
   * Cross-module connection key (see useConnections). When set and that
   * connection is switched OFF for the org, the link is hidden — a deliberate
   * "integration turned off" state, not a locked upsell. Items without this are
   * unaffected.
   */
  connection?: string;
  /**
   * Light this row ONLY on an exact path match. The static navs declare their
   * base paths in the Sidebar's EXACT_ONLY_HREFS set, but a nav built per
   * render (Sprintly's live projects/workspaces) has hrefs that set cannot
   * know, so those entries carry the rule with them.
   */
  exact?: boolean;
  /**
   * Extra route prefixes that also light this row. Sprintly's "Team" owns
   * /sprintly/team/manager and /sprintly/team/member but NOT the sibling
   * /sprintly/team/accounts, which is its own "Team & Roles" entry — plain
   * prefix matching would light both at once.
   */
  activePrefixes?: string[];
}

export interface NavGroup {
  label: string;
  icon: LucideIcon;
  children: NavLink[];
  /** Role required to see the whole group (e.g. Company = HR/Admin only). */
  requiresRole?: Role;
  /** Org must have availed this entitlement key to see this group. */
  feature?: string;
}

export type NavEntry = NavLink | NavGroup;

export function isGroup(entry: NavEntry): entry is NavGroup {
  return "children" in entry;
}

// HR portal nav — the 7-item IA from the Phase-2 brief (§14.2 of the build plan).
// Dashboard / Attendance / Reports are standalone links; People / Finance /
// Request / Company are drop-down groups. Stubbed sub-items point to placeholder
// pages until their slice (13–25) builds them.
export const hrNav: NavEntry[] = [
  { label: "Dashboard", href: "/teamora", icon: LayoutGrid },
  {
    label: "People",
    icon: Users,
    children: [
      { label: "Recruitment", href: "/teamora/people/recruitment", icon: UserPlus },
      { label: "Employees", href: "/teamora/people/employees", icon: Users },
    ],
  },
  { label: "Attendance", href: "/teamora/attendance", icon: Clock },
  {
    label: "Finance",
    icon: Wallet,
    children: [
      { label: "Payroll", href: "/teamora/finance/payroll", icon: Banknote },
      // Reimbursement folds under Payroll per the brief; kept as its own reachable
      // queue until Slice 22 makes it a Payroll tab.
      { label: "Reimbursement", href: "/teamora/finance/reimbursement", icon: Receipt },
      { label: "Office Expenses", href: "/teamora/finance/office-expenses", icon: Receipt },
    ],
  },
  { label: "Reports", href: "/teamora/reports", icon: BarChart3 },
  {
    label: "Request",
    icon: Inbox,
    children: [
      // "Leave" is the word people hunt for, and it appeared nowhere in this menu:
      // every leave request in the company lives on this page, under a label that
      // named the four smaller request types instead of the big one.
      { label: "Leave & Requests", href: "/teamora/requests/work-schedule", icon: CalendarDays },
      { label: "Document", href: "/teamora/requests/document", icon: FileText, stub: true },
      { label: "Payroll", href: "/teamora/requests/payroll", icon: Wallet, stub: true },
      { label: "Concern", href: "/teamora/requests/concern", icon: ShieldAlert, stub: true },
    ],
  },
  {
    label: "Company",
    icon: Building2,
    requiresRole: "hr_officer",
    children: [
      { label: "Benefits", href: "/teamora/company/benefits", icon: Heart },
      { label: "Departments", href: "/teamora/company/departments", icon: Network },
      { label: "Leave Type", href: "/teamora/company/leave-types", icon: Tag, stub: true },
      // ONE entry, not two. Staff sign-ins and the roles those sign-ins are given
      // used to be separate rows here, and an operator looking for either found
      // neither — so the page holds both halves and the label carries both nouns.
      // It sits under Company because the roles it defines belong to the
      // organisation rather than to TeamOra; the group's hr_officer check is what
      // /hr/team enforces server-side.
      { label: "Team & Roles", href: "/teamora/company/team", icon: UserCog },
      { label: "Settings", href: "/teamora/company/settings", icon: ScrollText },
      { label: "Holidays & Suspensions", href: "/teamora/company/holidays", icon: CalendarRange },
      { label: "FAQ", href: "/teamora/company/faq", icon: HelpCircle },
    ],
  },
];

// Employee portal nav (matches emp-Overview 170:4263) — stays flat.
export const employeeNav: NavEntry[] = [
  { label: "Overview", href: "/teamora", icon: LayoutGrid },
  // Same fix as the HR menu: this is where an employee FILES LEAVE, and "leave" is
  // the word they hunt for. Naming it after the schedule hid the one thing most of
  // them ever come here to do.
  { label: "Leave & Requests", href: "/teamora/work-schedule", icon: CalendarDays },
  { label: "My Attendance", href: "/teamora/my-attendance", icon: Clock },
  { label: "Payslips", href: "/teamora/payslips", icon: FileText },
  { label: "Claims", href: "/teamora/claims", icon: Wallet },
  { label: "Concerns", href: "/teamora/concerns", icon: ShieldAlert },
  { label: "Benefits", href: "/teamora/benefits", icon: Heart },
  { label: "FAQ", href: "/teamora/faq", icon: HelpCircle },
];



// Kiosk portal nav — internal website configurator (BD lead-gen tool).
export const kioskNav: NavEntry[] = [
  { label: "Overview", href: "/kiosk/overview", icon: LayoutGrid },
  { label: "New Website", href: "/kiosk/new", icon: Wand2 },
  { label: "Leads", href: "/kiosk/leads", icon: Inbox, requiresRole: "kiosk_bd" },
  { label: "Client Access", href: "/kiosk/access", icon: KeyRound, requiresRole: "kiosk_bd" },
];


// Accounting portal nav — the books. A flat list with one Reports group, in the
// order an accountant works: set up the accounts, record the entries, check they
// balance, then report. The `feature` + `lockable` pairs are the tier fence made
// visible: a Basic org SEES Bills sitting there locked with the tier that unlocks
// it, which is the whole argument for locking rather than hiding.
export const accountingNav: NavEntry[] = [
  { label: "Overview", href: "/accounting/overview", icon: LayoutGrid },
  { label: "Chart of Accounts", href: "/accounting", icon: BookOpen },
  { label: "Journal", href: "/accounting/journal", icon: NotebookText },
  { label: "Trial Balance", href: "/accounting/trial-balance", icon: Scale },
  { label: "Bank Reconciliation", href: "/accounting/bank-reconciliation", icon: Landmark },
  {
    label: "Reports",
    icon: BarChart3,
    children: [
      { label: "Statements", href: "/accounting/statements", icon: FileText },
      { label: "Statement of Account", href: "/accounting/statement-of-account", icon: FileText, feature: "accounting.invoicing", lockable: true, upsell: "Basic" },
      { label: "Aged Receivables", href: "/accounting/reports/aged-receivables", icon: TrendingUp, feature: "accounting.invoicing", lockable: true, upsell: "Basic" },
      { label: "Aged Payables", href: "/accounting/reports/aged-payables", icon: TrendingDown, feature: "accounting.bills", lockable: true, upsell: "Pro" },
      { label: "General Ledger", href: "/accounting/reports/general-ledger", icon: BookText },
      { label: "Cash Flow", href: "/accounting/reports/cash-flow", icon: ArrowLeftRight },
      { label: "VAT (BIR)", href: "/accounting/reports/vat", icon: Percent, feature: "accounting.tax_vat", lockable: true, upsell: "Full" },
      { label: "BIR 2550Q", href: "/accounting/reports/bir-2550q", icon: Percent, feature: "accounting.tax_vat", lockable: true, upsell: "Full" },
      { label: "BIR SLSP", href: "/accounting/reports/slsp", icon: Percent, feature: "accounting.tax_vat", lockable: true, upsell: "Full" },
    ],
  },
  { label: "Invoices", href: "/accounting/invoices", icon: FileText, feature: "accounting.invoicing", lockable: true, upsell: "Basic" },
  { label: "Recurring Invoices", href: "/accounting/recurring-invoices", icon: RefreshCw, feature: "accounting.invoicing", lockable: true, upsell: "Basic" },
  { label: "Purchase Orders", href: "/accounting/purchase-orders", icon: ShoppingCart, feature: "accounting.bills", lockable: true, upsell: "Pro" },
  { label: "Bills", href: "/accounting/bills", icon: Receipt, feature: "accounting.bills", lockable: true, upsell: "Pro" },
  { label: "Contacts", href: "/accounting/contacts", icon: Users },
  // The one bridge this repo has. Inventory→Ledger and Booking→Ledger exist in
  // the build this module came from; those modules are not sold here, so their
  // rows would be a lock on a door with no room behind it.
  { label: "Payroll → Ledger", href: "/accounting/payroll", icon: Banknote, feature: "accounting.payroll_posting", lockable: true, upsell: "Full", connection: "payroll_accounting" },
  { label: "Budget", href: "/accounting/budget", icon: Wallet, feature: "accounting.budgeting", lockable: true, upsell: "Full" },
  { label: "Business Settings", href: "/accounting/settings", icon: Building2, requiresRole: "acc_admin" },
  { label: "Integrations", href: "/admin/integrations", icon: Waypoints, requiresRole: "acc_admin" },
  { label: "Team & Roles", href: "/accounting/team", icon: UserCog, requiresRole: "acc_admin" },
];

export const navByVariant: Record<ShellVariant, NavEntry[]> = {
  hr: hrNav,
  employee: employeeNav,
  kiosk: kioskNav,
  // Sprintly and Zerem build their nav per render (live projects/workspaces,
  // CRM role rules) and hand it to AppShell via `navItems`, so there is no
  // static set to declare here.
  projects: [],
  crm: [],
  accounting: accountingNav,
};

export const workspaceLabel: Record<ShellVariant, string> = {
  hr: "WORKSPACE",
  employee: "MY SPACE",
  kiosk: "KIOSK",
  projects: "WORKSPACE",
  crm: "WORKSPACE",
  accounting: "WORKSPACE",
};

/** Sidebar brand label per portal. */
export const brandLabel: Record<ShellVariant, string> = {
  hr: "TeamOra",
  employee: "TeamOra",
  kiosk: "Kiosk",
  projects: "Sprintly",
  crm: "Zerem",
  // No product name of its own yet — the other three are branded (TeamOra,
  // Zerem, Sprintly) and this one is still called what it does.
  accounting: "Accounting",
};

/**
 * Sidebar brand mark per portal. The block itself is identical everywhere — a
 * brand-coloured chip plus the wordmark — so the portals read as one product;
 * only the glyph inside the chip says which one you are in. It doubles as the
 * collapsed rail's only identity, which is why it is a component and not an
 * image: it has to be legible at 32px.
 */
export const brandIcon: Record<ShellVariant, LucideIcon> = {
  hr: Zap,
  employee: Zap,
  kiosk: LayoutTemplate,
  projects: FolderKanban,
  crm: Handshake,
  accounting: Calculator,
};

/** Profile link per portal (null = this portal has no profile page). */
export const profileHref: Record<ShellVariant, string | null> = {
  hr: "/teamora/profile",
  employee: "/teamora/profile",
  kiosk: null,
  projects: "/sprintly/profile",
  // Zerem has no single account page — it resolves per role, so the CRM passes
  // `profileHrefOverride` instead of relying on this.
  crm: null,
  // Accounting has no profile page of its own; the shell falls back to the
  // platform user menu.
  accounting: null,
};

/** Human-friendly role label for the header UserMenu's identity block. */
export function roleLabel(roles: string[]): string {
  if (roles.includes("hr_officer")) return "HR Officer";
  if (roles.includes("super_admin")) return "Administrator";
  // The menu is shared by every portal now, so the non-HR tiers need a name
  // too — without these a Sprintly PM or a Zerem BD read as "Employee".
  if (roles.includes("pm_apm")) return "Project Manager";
  if (roles.includes("crm_admin")) return "CRM Manager";
  if (roles.includes("crm_bd")) return "Business Development";
  return "Employee";
}

/** Initials from a full name, e.g. "Dana Reyes" -> "DR". */
export function initials(name: string): string {
  return name
    .split(" ")
    .map((p) => p[0])
    .filter(Boolean)
    .slice(0, 2)
    .join("")
    .toUpperCase();
}
