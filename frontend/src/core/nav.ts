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
  type LucideIcon,
} from "lucide-react";

import type { Role } from "@/core/types/auth";

export type ShellVariant = "hr" | "employee";

export interface NavLink {
  label: string;
  href: string;
  icon: LucideIcon;
  /** Feature not built yet — the route renders a StubPage for now. */
  stub?: boolean;
}

export interface NavGroup {
  label: string;
  icon: LucideIcon;
  children: NavLink[];
  /** Role required to see the whole group (e.g. Company = HR/Admin only). */
  requiresRole?: Role;
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
      { label: "Work Schedule", href: "/teamora/requests/work-schedule", icon: CalendarDays },
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
      { label: "Rules", href: "/teamora/company/rules", icon: ScrollText, stub: true },
      { label: "Holidays & Suspensions", href: "/teamora/company/holidays", icon: CalendarRange },
      { label: "FAQ", href: "/teamora/company/faq", icon: HelpCircle },
    ],
  },
];

// Employee portal nav (matches emp-Overview 170:4263) — stays flat.
export const employeeNav: NavEntry[] = [
  { label: "Overview", href: "/teamora", icon: LayoutGrid },
  { label: "Work Schedule", href: "/teamora/work-schedule", icon: CalendarDays },
  { label: "My Attendance", href: "/teamora/my-attendance", icon: Clock },
  { label: "Payslips", href: "/teamora/payslips", icon: FileText },
  { label: "Claims", href: "/teamora/claims", icon: Wallet },
  { label: "Concerns", href: "/teamora/concerns", icon: ShieldAlert },
  { label: "Benefits", href: "/teamora/benefits", icon: Heart },
  { label: "FAQ", href: "/teamora/faq", icon: HelpCircle },
];

export const navByVariant: Record<ShellVariant, NavEntry[]> = {
  hr: hrNav,
  employee: employeeNav,
};

export const workspaceLabel: Record<ShellVariant, string> = {
  hr: "WORKSPACE",
  employee: "MY SPACE",
};

/** Human-friendly role label for the sidebar user card. */
export function roleLabel(roles: string[]): string {
  if (roles.includes("hr_officer")) return "HR Officer";
  if (roles.includes("super_admin")) return "Administrator";
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
