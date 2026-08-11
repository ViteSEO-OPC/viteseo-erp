"use client";

import Link from "next/link";
import {
  Users,
  CalendarRange,
  CreditCard,
  Filter,
  UsersRound,
  ShieldAlert,
  ScrollText,
  BarChart3,
  Wallet,
  ClipboardList,
  CalendarCheck,
  type LucideIcon,
} from "lucide-react";

import { PageHeader } from "@/modules/hr/components/page-header";

const REPORTS: {
  key: string;
  title: string;
  description: string;
  icon: LucideIcon;
}[] = [
  {
    key: "headcount",
    title: "Headcount & Movement",
    description: "Joiners, leavers, transfers; net change by department and month; filterable by department, location, date range.",
    icon: Users,
  },
  {
    key: "recruitment",
    title: "Recruitment Funnel",
    description: "Time-to-hire, source effectiveness, offer acceptance rate; funnel matches the pipeline stages.",
    icon: Filter,
  },
  {
    // Three month-scoped views behind one screen (summary / daily time record / leave), so the
    // library carries one card rather than three near-identical ones.
    key: "attendance_summary",
    title: "Attendance Summary",
    description: "Per-employee attendance for a month — present days, tardiness, absences and leave, each expandable to the day-by-day record. Includes the daily time record and a leave view.",
    icon: CalendarCheck,
  },
  {
    key: "leave",
    title: "Absence & Leaves",
    description: "Balances by employee and type, utilization and absence patterns. The peso value of unused leave is its own report below.",
    icon: CalendarRange,
  },
  {
    // MF-11 — its own card rather than a panel inside Absences: this is an accounting figure that
    // finance reconciles, not an HR utilisation chart, and it exports as a CSV of rows.
    key: "leave_liability",
    title: "Leave Liability",
    description: "What unused paid leave would cost if it were paid out today — the accrued obligation behind every leave balance, per employee and by department.",
    icon: Wallet,
  },
  {
    // FIN-042 — the pre-disbursement review screen. Sits next to Payroll & Cost because it is the
    // per-employee detail behind that report's totals.
    key: "payroll_register",
    title: "Payroll Register",
    description: "Per-employee gross-to-net for one run, with department subtotals and a comparison against the previous period. Its totals reconcile to the run exactly — review this before disbursing.",
    icon: ClipboardList,
  },
  {
    key: "compensation",
    title: "Compensation & Benefits",
    description: "Gross-to-net breakdowns, cost per head, benefits coverage, payroll run history for audit.",
    icon: CreditCard,
  },
  {
    key: "attrition",
    title: "Attrition & Tenure",
    description: "Voluntary vs. involuntary departures, regretted attrition, tenure curves — how long employees stay.",
    icon: BarChart3,
  },
  {
    key: "diversity",
    title: "Diversity",
    description: "Gender representation across departments; supports balanced representation. Restricted to HR and leadership.",
    icon: UsersRound,
  },
  {
    key: "health_safety",
    title: "Health & Safety",
    description: "Incident tracking required for the agency. Employees may report concerns anonymously, with an option to name the person being reported.",
    icon: ShieldAlert,
  },
  {
    key: "audit",
    title: "Audit Trail",
    description: "System activity log: who did what, when, and to which record — record changes, approvals, logins, and report exports; restricted to admins and HR leadership.",
    icon: ScrollText,
  },
];

export default function ReportsPage() {
  return (
    <div className="p-4 sm:p-8">
      <PageHeader title="Reports" subtitle="Build, schedule and export across every module" />

      <div className="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-2">
        {REPORTS.map(({ key, title, description, icon: Icon }) => (
          <Link
            key={key}
            href={`/teamora/reports/${key}`}
            className="flex gap-4 rounded-card border border-border bg-card p-6 transition-colors hover:border-brand/60"
          >
            <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-brand-subtle text-brand">
              <Icon className="size-6" />
            </span>
            <div className="min-w-0">
              <h2 className="text-h3 font-bold text-foreground">{title}</h2>
              <p className="mt-1.5 text-sm text-muted-foreground">{description}</p>
            </div>
          </Link>
        ))}
      </div>
    </div>
  );
}
