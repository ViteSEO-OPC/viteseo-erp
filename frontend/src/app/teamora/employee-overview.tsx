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

  // Shared mode: show the single pool. Not-shared: the Annual Leave balance.
  const pool = summary?.pool ?? null;
  const annual = summary?.balances?.find((b) => b.leave_type.code === "ANL");

  // Merge leave + reimbursements into one recent "My Requests" feed.
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
