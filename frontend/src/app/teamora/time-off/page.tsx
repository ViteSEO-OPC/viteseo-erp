"use client";

import Link from "next/link";
import { PageHeader } from "@/core/components/page-header";
import { StatCard } from "@/core/components/stat-card";
import { StatusBadge } from "@/core/components/status-badge";
import { formatDate } from "@/core/lib/format";
import { LeaveBalanceCard } from "@/modules/hr/components/leave-balance-card";
import { LeaveRequestForm } from "@/modules/hr/components/leave-request-form";
import { HalfDayRequestForm } from "@/modules/hr/components/half-day-request-form";
import { TimeOffCalendar } from "@/modules/hr/components/time-off-calendar";
import {
  useLeaveTypes,
  useMyLeaveBalances,
  useLeaveRequests,
} from "@/modules/hr/hooks/use-leave";
import { useHalfDayRequests } from "@/modules/hr/hooks/use-half-day";

interface HistItem {
  key: string;
  title: string;
  date: string;
  status: string;
  at: string;
}

export default function TimeOffPage() {
  const { data: balances } = useMyLeaveBalances();
  const { data: types } = useLeaveTypes();
  const { data: requests } = useLeaveRequests();
  const { data: halfDays } = useHalfDayRequests();

  const annual = balances?.find((b) => b.leave_type.code === "ANL");
  const sick = balances?.find((b) => b.leave_type.code === "SCK");
  const pendingCount =
    (requests ?? []).filter((r) => r.status === "pending").length +
    (halfDays ?? []).filter((h) => h.status === "pending").length;

  // Combined leave + half-day history, newest first.
  const history: HistItem[] = [
    ...(requests ?? []).map((l) => ({
      key: `l${l.id}`,
      title: l.leave_type.name,
      date:
        l.start_date === l.end_date
          ? formatDate(l.start_date)
          : `${formatDate(l.start_date)} – ${formatDate(l.end_date)}`,
      status: l.status,
      at: l.created_at,
    })),
    ...(halfDays ?? []).map((h) => ({
      key: `h${h.id}`,
      title: `Half-day (${h.period})`,
      date: `${formatDate(h.work_date)} · Unpaid`,
      status: h.status,
      at: h.created_at,
    })),
  ].sort((a, b) => +new Date(b.at) - +new Date(a.at));

  return (
    <div className="p-8">
      <PageHeader
        title="Time Off"
        subtitle="Request leave and track your remaining balance."
      />

      {/* Balance summary */}
      <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        {annual ? <LeaveBalanceCard balance={annual} /> : <StatCard label="Annual Leave" value="—" />}
        {sick ? <LeaveBalanceCard balance={sick} /> : <StatCard label="Sick Leave" value="—" />}
        <StatCard label="Pending Requests" value={pendingCount} subNote="Awaiting HR decision" />
      </div>

      {/* Left: the two request cards · Right: calendar + history */}
      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="space-y-6">
          <LeaveRequestForm types={types ?? []} balances={balances ?? []} />
          <HalfDayRequestForm />
        </div>

        <div className="space-y-6">
          <TimeOffCalendar leaves={requests ?? []} halfDays={halfDays ?? []} />

          <div className="rounded-card border border-border bg-card p-6">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-h3 font-bold text-foreground">My Request History</h2>
              <Link href="/teamora/requests" className="text-sm font-semibold text-brand hover:underline">
                View all
              </Link>
            </div>
            <div className="divide-y divide-border">
              {history.length === 0 ? (
                <p className="py-6 text-sm text-muted-foreground">No requests yet.</p>
              ) : (
                history.slice(0, 6).map((r) => (
                  <div key={r.key} className="flex items-center justify-between py-3">
                    <div>
                      <p className="text-sm font-semibold text-foreground">{r.title}</p>
                      <p className="text-caption text-muted-foreground">{r.date}</p>
                    </div>
                    <StatusBadge status={r.status} />
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
