"use client";

import Link from "next/link";
import { useAuth } from "@/core/auth-context";
import { PageHeader } from "@/modules/hr/components/page-header";
import { StatCard } from "@/core/components/stat-card";
import { StatusBadge } from "@/core/components/status-badge";
import { DataTable, type Column } from "@/core/components/data-table";
import { formatPeso, formatDate } from "@/core/lib/format";
import { TimeClockPanel } from "@/modules/hr/components/time-clock-panel";
import { LeaveBalanceCard } from "@/modules/hr/components/leave-balance-card";
import { UpcomingMeetingsCard } from "@/modules/hr/components/upcoming-meetings-card";
import { useMyLeaveSummary, useLeaveRequests } from "@/modules/hr/hooks/use-leave";
import { useReimbursements } from "@/modules/hr/hooks/use-reimbursements";
import { useHalfDayRequests } from "@/modules/hr/hooks/use-half-day";
import { useEarlyOutRequests } from "@/modules/hr/hooks/use-early-out";
import { useOvertimeRequests } from "@/modules/hr/hooks/use-overtime";
import {
  useMyDocumentRequests,
  useMyChangeRequests,
  useMyPayslipRequests,
  useMyPayrollInquiries,
} from "@/modules/hr/hooks/use-requests";
import { useMyNextPayday } from "@/modules/hr/hooks/use-payroll";
import { payFrequencyLabel } from "@/modules/hr/types/employee";

interface MyRequestRow {
  key: string;
  type: string;
  date: string;
  status: string;
  at: string;
}

export default function EmployeeOverviewPage() {
  const { user } = useAuth();
  const { data: summary } = useMyLeaveSummary();
  const { data: leaves } = useLeaveRequests();
  const { data: claims } = useReimbursements();
  const { data: nextPayday } = useMyNextPayday();
  // "My Requests" merged leave + reimbursements only, so an employee who filed overtime, a
  // half-day, an early-out, or asked for a document, a profile change, a payslip or a payroll
  // correction saw no trace of it here — the one screen that is supposed to answer "what did I
  // file and where has it got to". Each of these is scoped to the caller server-side.
  const { data: halfDays } = useHalfDayRequests();
  const { data: earlyOuts } = useEarlyOutRequests();
  const { data: overtimes } = useOvertimeRequests();
  const { data: docReqs } = useMyDocumentRequests();
  const { data: changeReqs } = useMyChangeRequests();
  const { data: payslipReqs } = useMyPayslipRequests();
  const { data: inquiries } = useMyPayrollInquiries();

  // Shared mode: show the single pool. Not-shared: the Annual Leave balance.
  const pool = summary?.pool ?? null;
  const annual = summary?.balances?.find((b) => b.leave_type.code === "ANL");

  // Every kind of request the employee can file, newest first. Concerns are deliberately absent:
  // they have their own page, and one may be filed anonymously — surfacing it on a shared screen
  // beside somebody's name is exactly what that option exists to avoid.
  const requests: MyRequestRow[] = [
    ...(leaves ?? []).map((l) => ({
      key: `l${l.id}`,
      type: l.leave_type.name,
      date:
        l.start_date === l.end_date
          ? formatDate(l.start_date)
          : `${formatDate(l.start_date)} – ${formatDate(l.end_date)}`,
      status: l.status,
      at: l.created_at,
    })),
    ...(claims ?? []).map((c) => ({
      key: `c${c.id}`,
      type: `Reimbursement (${formatPeso(c.amount)})`,
      date: formatDate(c.created_at),
      status: c.status,
      at: c.created_at,
    })),
    ...(halfDays ?? []).map((h) => ({
      key: `hd${h.id}`,
      type: "Half-day",
      date: formatDate(h.work_date),
      status: h.status,
      at: h.created_at,
    })),
    ...(earlyOuts ?? []).map((e) => ({
      key: `eo${e.id}`,
      type: "Early-out",
      date: formatDate(e.work_date),
      status: e.status,
      at: e.created_at,
    })),
    ...(overtimes ?? []).map((o) => ({
      key: `ot${o.id}`,
      type: `Overtime (${o.hours}h)`,
      date: formatDate(o.work_date),
      status: o.status,
      at: o.created_at,
    })),
    ...(docReqs ?? []).map((d) => ({
      key: `dr${d.id}`,
      type: `Document — ${d.type}`,
      date: formatDate(d.created_at),
      status: d.status,
      at: d.created_at,
    })),
    ...(changeReqs ?? []).map((c) => ({
      key: `cr${c.id}`,
      type: "Profile change",
      date: formatDate(c.created_at),
      status: c.status,
      at: c.created_at,
    })),
    ...(payslipReqs ?? []).map((p) => ({
      key: `pr${p.id}`,
      type: "Payslip copy",
      date: formatDate(p.created_at),
      status: p.status,
      at: p.created_at,
    })),
    ...(inquiries ?? []).map((q) => ({
      key: `pi${q.id}`,
      type: "Payroll inquiry",
      date: formatDate(q.created_at),
      status: q.status,
      at: q.created_at,
    })),
  ]
    .sort((a, b) => +new Date(b.at) - +new Date(a.at))
    .slice(0, 6);

  const columns: Column<MyRequestRow>[] = [
    {
      header: "Type",
      cell: (r) => (
        <div>
          <p className="font-semibold text-foreground">{r.type}</p>
          <p className="text-caption text-muted-foreground">Submitted on {formatDate(r.at)}</p>
        </div>
      ),
    },
    { header: "Date", cell: (r) => r.date },
    { header: "Status", align: "right", cell: (r) => <StatusBadge status={r.status} /> },
  ];

  // Time-aware greeting + full date, in Manila time (per the design).
  const manilaHour = Number(
    new Date().toLocaleString("en-PH", { timeZone: "Asia/Manila", hour: "2-digit", hour12: false }),
  );
  const greeting =
    manilaHour < 12 ? "Good morning" : manilaHour < 18 ? "Good afternoon" : "Good evening";
  const todayLong = new Date().toLocaleDateString("en-GB", {
    timeZone: "Asia/Manila",
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
  });

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title={`${greeting}, ${user?.name?.split(" ")[0] ?? "there"} 👋`}
        subtitle={`${todayLong} · Everything in one place.`}
      />

      <div className="mt-6">
        <TimeClockPanel />
      </div>

      {/* Leave balance · upcoming meeting · next payday */}
      <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        {summary?.mode === "shared" ? (
          pool ? (
            <LeaveBalanceCard
              label="Leave Pool"
              entitled={pool.entitled_days}
              remaining={pool.remaining_days}
              used={pool.used_days}
            />
          ) : (
            <StatCard label="Leave Pool" value="—" />
          )
        ) : annual ? (
          <LeaveBalanceCard
            label={annual.leave_type.name}
            entitled={annual.entitled_days}
            remaining={annual.remaining_days}
            used={annual.used_days}
          />
        ) : (
          <StatCard label="Annual Leave" value="—" />
        )}
        <UpcomingMeetingsCard />
        <StatCard
          label="Next Payday"
          value={nextPayday ? formatDate(nextPayday.pay_date) : "—"}
          subNote={nextPayday ? payFrequencyLabel(nextPayday.frequency) : undefined}
        />
      </div>

      {/* My requests */}
      <div className="mt-6 rounded-card border border-border bg-card p-6">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-h3 font-bold text-foreground">My Requests</h2>
        </div>
        <DataTable
          columns={columns}
          rows={requests}
          rowKey={(r) => r.key}
          empty="No requests yet."
        />
      </div>
    </div>
  );
}
