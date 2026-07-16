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
  type LucideIcon,
} from "lucide-react";

import { PageHeader } from "@/core/components/page-header";

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
    key: "leave",
    title: "Absence & Leaves",
    description: "Balances by employee and type, utilization, absence patterns, liability accrual from unused leave.",
    icon: CalendarRange,
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
    <div className="p-8">
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
