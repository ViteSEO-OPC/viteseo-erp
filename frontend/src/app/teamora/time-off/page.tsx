"use client";

import { useState, useMemo } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { PageHeader } from "@/modules/hr/components/page-header";
import { StatCard } from "@/core/components/stat-card";
import { StatusBadge } from "@/core/components/status-badge";
import { RejectionReasonDialog } from "@/core/components/rejection-reason-dialog";
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
import { SimpleSelect } from "@/modules/hr/ui/select";

interface HistItem {
  key: string;
  title: string;
  date: string;
  status: string;
  at: string;
  reason: string | null;
}

export default function TimeOffPage() {
  const { data: balances } = useMyLeaveBalances();
  const { data: types } = useLeaveTypes();
  const { data: requests } = useLeaveRequests();
  const { data: halfDays } = useHalfDayRequests();
  const [declined, setDeclined] = useState<HistItem | null>(null);
  const [page, setPage] = useState(1);
  const [size, setSize] = useState(6);

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
      reason: l.review_note,
    })),
    ...(halfDays ?? []).map((h) => ({
      key: `h${h.id}`,
      title: `Half-day (${h.period})`,
      date: `${formatDate(h.work_date)} · Unpaid`,
      status: h.status,
      at: h.created_at,
      reason: h.review_note,
    })),
  ].sort((a, b) => +new Date(b.at) - +new Date(a.at));

  const pageSizes = [6, 12, 24, 48];
  const totalPages = Math.max(1, Math.ceil(history.length / size));
  const safePage = Math.min(page, totalPages);
  const pageHistory = useMemo(
    () => history.slice((safePage - 1) * size, safePage * size),
    [history, safePage, size],
  );

  return (
    <div className="p-4 sm:p-8">
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
            </div>
            <div className="divide-y divide-border">
              {history.length === 0 ? (
                <p className="py-6 text-sm text-muted-foreground">No requests yet.</p>
              ) : (
                pageHistory.map((r) =>
                  r.status === "rejected" ? (
                    <button
                      key={r.key}
                      type="button"
                      onClick={() => setDeclined(r)}
                      className="flex w-full items-center justify-between py-3 text-left transition-colors hover:bg-muted/40"
                    >
                      <div>
                        <p className="text-sm font-semibold text-foreground">{r.title}</p>
                        <p className="text-caption text-danger">{r.date} · Tap to see why</p>
                      </div>
                      <StatusBadge status={r.status} />
                    </button>
                  ) : (
                    <div key={r.key} className="flex items-center justify-between py-3">
                      <div>
                        <p className="text-sm font-semibold text-foreground">{r.title}</p>
                        <p className="text-caption text-muted-foreground">{r.date}</p>
                      </div>
                      <StatusBadge status={r.status} />
                    </div>
                  ),
                )
              )}
            </div>
            {history.length > 0 && (
              <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                <div className="flex items-center gap-2 text-caption text-muted-foreground">
                  <span>Rows per page</span>
                  <SimpleSelect
                    className="h-7 w-16"
                    value={String(size)}
                    onValueChange={(v) => { setSize(Number(v)); setPage(1); }}
                    options={pageSizes.map((n) => ({ value: String(n), label: String(n) }))}
                  />
                  <span className="hidden sm:inline">
                    · Showing {(safePage - 1) * size + 1}–{Math.min(safePage * size, history.length)} of {history.length}
                  </span>
                </div>

                <div className="flex items-center gap-1">
                  <button
                    type="button"
                    disabled={safePage <= 1}
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                    className="inline-flex size-7 items-center justify-center rounded-md border border-input bg-card text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                    aria-label="Previous page"
                  >
                    <ChevronLeft className="size-4" />
                  </button>
                  <span className="px-2 text-caption text-foreground">
                    Page {safePage} of {totalPages}
                  </span>
                  <button
                    type="button"
                    disabled={safePage >= totalPages}
                    onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                    className="inline-flex size-7 items-center justify-center rounded-md border border-input bg-card text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                    aria-label="Next page"
                  >
                    <ChevronRight className="size-4" />
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>

      <RejectionReasonDialog
        open={!!declined}
        onOpenChange={(o) => !o && setDeclined(null)}
        title={declined?.key.startsWith("h") ? "Half-day declined" : "Leave declined"}
        subject={declined ? `${declined.title} · ${declined.date}` : undefined}
        reason={declined?.reason ?? null}
      />
    </div>
  );
}
