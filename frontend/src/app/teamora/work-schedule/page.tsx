"use client";

import { useState, useMemo } from "react";
import { cn } from "@/lib/utils";
import { PageHeader } from "@/modules/hr/components/page-header";
import { DataTable, type Column } from "@/core/components/data-table";
import { StatusBadge } from "@/core/components/status-badge";
import { Button } from "@/components/ui/button";
import { notify as toast } from "@/core/lib/notify";
import { formatDate, formatDateTime } from "@/core/lib/format";
import {
  RequestDetailDialog,
  type DetailField,
} from "@/modules/hr/components/request-detail-dialog";
import { LeaveSummaryCard } from "@/modules/hr/components/leave-summary-card";
import { LeaveRequestForm } from "@/modules/hr/components/leave-request-form";
import { HalfDayRequestForm } from "@/modules/hr/components/half-day-request-form";
import { EarlyOutRequestForm } from "@/modules/hr/components/early-out-request-form";
import { OvertimeRequestForm } from "@/modules/hr/components/overtime-request-form";
import { TimeOffCalendar } from "@/modules/hr/components/time-off-calendar";
import { CancelLeaveDialog } from "@/modules/hr/components/cancel-leave-dialog";
import {
  useLeaveTypes,
  useMyLeaveSummary,
  useLeaveRequests,
  useCancelLeaveRequest,
} from "@/modules/hr/hooks/use-leave";
import {
  useHalfDayRequests,
  useCancelHalfDayRequest,
} from "@/modules/hr/hooks/use-half-day";
import {
  useEarlyOutRequests,
  useCancelEarlyOutRequest,
} from "@/modules/hr/hooks/use-early-out";
import {
  useOvertimeRequests,
  useCancelOvertimeRequest,
} from "@/modules/hr/hooks/use-overtime";

type RequestTab = "leave" | "half" | "early" | "overtime";

// Fixed rows per page — deliberately not user-selectable (see DataTable).
const PAGE_SIZE = 10;

const REQUEST_TITLES: Record<RequestTab, string> = {
  leave: "Request Leave",
  half: "Request Half-Day",
  early: "Request Early-Out",
  overtime: "Request overtime",
};

/** Days without a pointless ".0": 5, 0.5. */
function formatDays(n: number): string {
  return Number.isInteger(n) ? String(n) : n.toFixed(1);
}

/**
 * "Apr 1 – Apr 5, 2026" for a range inside one year, so the history table doesn't
 * carry the year twice; the full date on both sides when it straddles a new year.
 */
function formatDateRange(start: string, end: string): string {
  if (start === end) return formatDate(start);
  const from = new Date(start);
  const to = new Date(end);
  if (from.getFullYear() !== to.getFullYear()) {
    return `${formatDate(start)} – ${formatDate(end)}`;
  }
  const fromShort = from.toLocaleDateString("en-PH", { month: "short", day: "numeric" });
  return `${fromShort} – ${formatDate(end)}`;
}

/**
 * One row of the combined history, carrying everything its detail modal needs. The four
 * request kinds have different shapes, so each maps its own `fields` and `decision`
 * rather than the modal switching on `kind`.
 */
interface HistItem {
  key: string;
  id: number;
  kind: "leave" | "half" | "early" | "overtime";
  title: string;
  dates: string;
  /** null where a day count is meaningless — an early-out or an overtime window. */
  days: number | null;
  status: string;
  at: string;
  /** The employee's own words when filing. */
  reason: string;
  /** HR's verdict — `note` is the decline reason on a rejected request. */
  decision: { at: string | null; by?: string | null; note: string | null };
  /** The label/value rows shown in the detail modal. */
  fields: DetailField[];
  /** The withdrawal trail, once the employee has cancelled it. */
  cancelled: { at: string; by: string | null; reason: string | null } | null;
  /** Employees may withdraw only their own still-PENDING request, whatever the kind. */
  cancellable: boolean;
}

/**
 * Figma "segmented-toggle": a light-gray track holding equal pills; the active
 * one is a white, subtly-shadowed pill with bold ink text. Picks which kind of
 * time-off request the single card shows.
 */
function RequestTabs({
  value,
  onChange,
}: {
  value: RequestTab;
  onChange: (tab: RequestTab) => void;
}) {
  const tabs: { value: RequestTab; label: string }[] = [
    { value: "leave", label: "Leave" },
    { value: "half", label: "Half-Day" },
    { value: "early", label: "Early Out" },
    { value: "overtime", label: "Overtime" },
  ];

  return (
    <div
      role="tablist"
      className="flex w-full shrink-0 gap-1 rounded-xl bg-muted p-1 sm:w-[440px]"
    >
      {tabs.map((t) => {
        const active = value === t.value;
        return (
          <button
            key={t.value}
            type="button"
            role="tab"
            aria-selected={active}
            onClick={() => onChange(t.value)}
            className={cn(
              "flex-1 rounded-lg py-2 text-center text-sm transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50",
              active
                ? "bg-card font-bold text-foreground shadow-sm"
                : "font-semibold text-muted-foreground hover:text-foreground",
            )}
          >
            {t.label}
          </button>
        );
      })}
    </div>
  );
}

export default function TimeOffPage() {
  const { data: summary } = useMyLeaveSummary();
  const { data: types } = useLeaveTypes();
  const { data: requests } = useLeaveRequests();
  const { data: halfDays } = useHalfDayRequests();
  const { data: earlyOuts } = useEarlyOutRequests();
  const { data: overtimes } = useOvertimeRequests();
  // One dialog, four endpoints — `handleCancel` dispatches on the row's kind.
  const cancelMut = useCancelLeaveRequest();
  const cancelHalfDayMut = useCancelHalfDayRequest();
  const cancelEarlyOutMut = useCancelEarlyOutRequest();
  const cancelOvertimeMut = useCancelOvertimeRequest();
  const [tab, setTab] = useState<RequestTab>("leave");
  const [detail, setDetail] = useState<HistItem | null>(null);
  const [cancelTarget, setCancelTarget] = useState<HistItem | null>(null);

  const mode = summary?.mode ?? "not_shared";
  const balances = useMemo(() => summary?.balances ?? [], [summary]);
  const pool = summary?.pool ?? null;

  // Shared: the pool is the headline and the types are names only. Not-shared:
  // each paid type carries its own credits and the headline is their total.
  const paidCodes = useMemo(
    () => new Set((types ?? []).filter((t) => t.is_paid).map((t) => t.code)),
    [types],
  );

  const listItems =
    mode === "shared"
      ? (types ?? [])
          .filter((t) => t.is_paid)
          .map((t) => ({
            key: `t${t.id}`,
            name: t.name,
            remaining: 0,
            entitled: 0,
            used: 0,
          }))
      : balances
          .filter((b) => paidCodes.has(b.leave_type.code))
          .map((b) => ({
            key: `b${b.id}`,
            name: b.leave_type.name,
            remaining: b.remaining_days,
            entitled: b.entitled_days,
            used: b.used_days,
          }));

  // The headline balance. Not-shared has no single pool, so the card totals every
  // paid type instead — the per-type breakdown is right below it either way.
  const featured =
    mode === "shared"
      ? pool
        ? {
            entitled: pool.entitled_days,
            remaining: pool.remaining_days,
            used: pool.used_days,
          }
        : null
      : listItems.length > 0
        ? listItems.reduce(
            (acc, it) => ({
              entitled: acc.entitled + it.entitled,
              remaining: acc.remaining + it.remaining,
              used: acc.used + it.used,
            }),
            { entitled: 0, remaining: 0, used: 0 },
          )
        : null;

  const pendingCount =
    (requests ?? []).filter((r) => r.status === "pending").length +
    (halfDays ?? []).filter((h) => h.status === "pending").length +
    (earlyOuts ?? []).filter((e) => e.status === "pending").length +
    (overtimes ?? []).filter((o) => o.status === "pending").length;

  // Combined leave / half-day / early-out / overtime history, newest first.
  const history: HistItem[] = [
    ...(requests ?? []).map((l) => {
      const dates = formatDateRange(l.start_date, l.end_date);
      return {
        key: `l${l.id}`,
        id: l.id,
        kind: "leave" as const,
        title: l.leave_type.name,
        dates,
        days: l.days,
        status: l.status,
        at: l.created_at,
        reason: l.reason,
        decision: { at: l.reviewed_at, by: l.reviewed_by, note: l.review_note },
        fields: [
          { label: "Leave type", value: l.leave_type.name },
          { label: "Dates", value: dates },
          {
            label: "Days",
            value: l.is_half_day
              ? `${l.days} (half day · ${l.half_day_period ?? "—"})`
              : l.days,
          },
          {
            label: "Your credits",
            value:
              l.status === "approved"
                ? `${l.days} day(s) deducted`
                : l.status === "cancelled"
                  ? "Returned to your balance"
                  : undefined,
          },
        ],
        cancelled: l.cancelled_at
          ? { at: l.cancelled_at, by: l.cancelled_by, reason: l.cancel_reason }
          : null,
        cancellable: l.status === "pending",
      };
    }),
    ...(halfDays ?? []).map((h) => ({
      key: `h${h.id}`,
      id: h.id,
      kind: "half" as const,
      title: `Half-day (${h.period})`,
      dates: formatDate(h.work_date),
      days: 0.5,
      status: h.status,
      at: h.created_at,
      reason: h.reason,
      decision: { at: h.reviewed_at, by: h.reviewed_by, note: h.review_note },
      fields: [
        { label: "Date", value: formatDate(h.work_date) },
        {
          label: "Half off",
          value: h.period === "AM" ? "Morning (AM) — afternoon worked" : "Afternoon (PM) — morning worked",
        },
        { label: "Pay", value: <span className="text-danger">Unpaid · 0.5 day</span> },
      ],
      cancelled: h.cancelled_at
        ? { at: h.cancelled_at, by: h.cancelled_by, reason: h.cancel_reason }
        : null,
      cancellable: h.status === "pending",
    })),
    ...(earlyOuts ?? []).map((e) => ({
      key: `e${e.id}`,
      id: e.id,
      kind: "early" as const,
      title: `Early-out (${e.early_out_time})`,
      dates: formatDate(e.work_date),
      days: null,
      status: e.status,
      at: e.created_at,
      reason: e.reason,
      decision: { at: e.reviewed_at, by: e.reviewed_by, note: e.review_note },
      fields: [
        { label: "Date", value: formatDate(e.work_date) },
        { label: "Leaving at", value: e.early_out_time },
        { label: "Pay", value: <span className="text-danger">Unpaid · undertime</span> },
      ],
      cancelled: e.cancelled_at
        ? { at: e.cancelled_at, by: e.cancelled_by, reason: e.cancel_reason }
        : null,
      cancellable: e.status === "pending",
    })),
    ...(overtimes ?? []).map((o) => ({
      key: `o${o.id}`,
      id: o.id,
      kind: "overtime" as const,
      title: `Overtime (${o.hours.toFixed(1)} hrs)`,
      dates: formatDate(o.work_date),
      days: null,
      status: o.status,
      at: o.created_at,
      reason: o.reason,
      decision: { at: o.reviewed_at, by: o.reviewed_by, note: o.review_note },
      fields: [
        { label: "Date", value: formatDate(o.work_date) },
        {
          label: "Window",
          value: o.time_from && o.time_to ? `${o.time_from} – ${o.time_to}` : undefined,
        },
        { label: "Hours", value: `${o.hours.toFixed(1)} hrs` },
        { label: "Pay", value: <span className="text-success">Paid · overtime</span> },
      ],
      cancelled: o.cancelled_at
        ? { at: o.cancelled_at, by: o.cancelled_by, reason: o.cancel_reason }
        : null,
      // Pending only. Cancelling an APPROVED overtime can create an overpayment to claw back,
      // so the service keeps that with HR — offering the button here would only 422.
      cancellable: o.status === "pending",
    })),
  ].sort((a, b) => +new Date(b.at) - +new Date(a.at));

  // A day count is only meaningful for leave and half-days; an early-out or an
  // overtime window states its extent in the Type column instead.
  const columns: Column<HistItem>[] = [
    {
      header: "Type",
      cell: (r) => <span className="font-semibold text-foreground">{r.title}</span>,
    },
    {
      header: "Dates",
      cell: (r) => <span className="whitespace-nowrap">{r.dates}</span>,
    },
    {
      header: "Days",
      cell: (r) => (r.days === null ? "—" : formatDays(r.days)),
    },
    {
      header: "Reason",
      cell: (r) => (
        <span className="block max-w-[18rem] truncate" title={r.reason}>
          {r.reason}
        </span>
      ),
    },
    {
      header: "Status",
      cell: (r) => (
        <>
          <StatusBadge status={r.status} />
          {r.status === "rejected" && (
            <span className="mt-1 block text-caption text-danger">Tap to see why</span>
          )}
        </>
      ),
    },
    {
      header: "",
      align: "right",
      cell: (r) =>
        r.cancellable ? (
          <Button
            variant="ghost"
            size="sm"
            className="h-7 px-2 text-danger hover:bg-danger/10 hover:text-danger"
            onClick={(e) => {
              e.stopPropagation();
              setCancelTarget(r);
            }}
          >
            Cancel
          </Button>
        ) : null,
    },
  ];

  async function handleCancel(reason: string) {
    if (!cancelTarget) return;

    const { mutation, label } = {
      leave: { mutation: cancelMut, label: "leave request" },
      half: { mutation: cancelHalfDayMut, label: "half-day request" },
      early: { mutation: cancelEarlyOutMut, label: "early-out request" },
      overtime: { mutation: cancelOvertimeMut, label: "overtime request" },
    }[cancelTarget.kind];

    try {
      await mutation.mutateAsync({ id: cancelTarget.id, reason });
      toast.success("Request cancelled", `Your ${label} has been cancelled.`);
    } catch {
      toast.error("Couldn't cancel", "Please try again.");
      // Rethrow so the dialog stays open for a retry.
      throw new Error("cancel failed");
    }
  }

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Work Schedule"
        subtitle="Request leave, half-days, early-outs and overtime — and track your balance."
      />

      {/* Balance summary beside the one tabbed request card. */}
      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <LeaveSummaryCard
          // Shared mode pools everything into Service Incentive Leave, so the headline names it
          // rather than saying "Leave Pool" — SIL is what the payslip and the law call it.
          featuredLabel={mode === "shared" ? "SIL Credits" : "Total Leave Credits"}
          featured={featured}
          emptyNote={
            mode === "shared" ? "No SIL credits for this year" : "No credits for this year"
          }
          pending={pendingCount}
          types={listItems}
          showCredits={mode !== "shared"}
          note={
            mode === "shared"
              ? "Every paid leave type draws from your SIL credits. Unpaid leave (LWOP) is unlimited and takes nothing from them."
              : undefined
          }
        />

        <div className="rounded-card border border-border bg-card p-6 lg:col-span-2">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <h2 className="text-h3 font-bold text-foreground">{REQUEST_TITLES[tab]}</h2>
            <RequestTabs value={tab} onChange={setTab} />
          </div>

          <div className="mt-6">
            {tab === "leave" ? (
              <LeaveRequestForm
                types={types ?? []}
                balances={balances}
                mode={mode}
                pool={pool}
              />
            ) : tab === "half" ? (
              <HalfDayRequestForm />
            ) : tab === "early" ? (
              <EarlyOutRequestForm />
            ) : (
              <OvertimeRequestForm />
            )}
          </div>
        </div>
      </div>

      <div className="mt-6">
        <TimeOffCalendar
          leaves={requests ?? []}
          halfDays={halfDays ?? []}
          earlyOuts={earlyOuts ?? []}
        />
      </div>

      {/* Every row opens the full detail, so a decline reason is one tap away. */}
      <DataTable
        className="mt-6"
        title="My Request History"
        columns={columns}
        rows={history}
        rowKey={(r) => r.key}
        onRowClick={setDetail}
        pageSize={PAGE_SIZE}
        searchable={false}
        filterable={false}
        empty="No requests yet."
      />

      {detail && (
        <RequestDetailDialog
          open
          onOpenChange={(o) => !o && setDetail(null)}
          title={detail.title}
          status={detail.status}
          submitted={detail.at}
          fields={detail.fields}
          note={{ label: "Your reason", text: detail.reason }}
          decision={{
            label: detail.status === "rejected" ? "Why it was declined" : "HR's decision",
            by: detail.decision.by,
            at: detail.decision.at,
            note: detail.decision.note,
          }}
          actions={
            detail.cancellable ? (
              <Button
                variant="outline"
                className="border-danger text-danger hover:bg-danger/10 hover:text-danger"
                onClick={() => {
                  setDetail(null);
                  setCancelTarget(detail);
                }}
              >
                Cancel this request
              </Button>
            ) : undefined
          }
        >
          {detail.cancelled && (
            <div className="space-y-1.5">
              <p className="text-label font-semibold uppercase tracking-wide text-brand">
                Cancelled
              </p>
              <p className="text-caption text-muted-foreground">
                {detail.cancelled.by ? `${detail.cancelled.by} · ` : ""}
                {formatDateTime(detail.cancelled.at)}
              </p>
              {detail.cancelled.reason && (
                <p className="whitespace-pre-wrap rounded-lg border border-border bg-muted/40 px-3.5 py-3 text-sm text-foreground">
                  {detail.cancelled.reason}
                </p>
              )}
            </div>
          )}
        </RequestDetailDialog>
      )}

      <CancelLeaveDialog
        open={!!cancelTarget}
        onOpenChange={(o) => !o && setCancelTarget(null)}
        subject={cancelTarget ? `${cancelTarget.title} · ${cancelTarget.dates}` : undefined}
        onConfirm={handleCancel}
      />
    </div>
  );
}
