"use client";

import { useMemo, useState } from "react";
import { Zap, Check, X, Ban, CalendarPlus } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { cn } from "@/lib/utils";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import { EmployeeCell } from "@/modules/hr/components/employee-cell";
import { StatusBadge } from "@/core/components/status-badge";
import { ReviewDialog } from "@/core/components/review-dialog";
import { CancelLeaveDialog } from "@/modules/hr/components/cancel-leave-dialog";
import { ReopenAction } from "@/modules/hr/components/reopen-action";
import {
  RequestDetailDialog,
  type DetailField,
} from "@/modules/hr/components/request-detail-dialog";
import {
  DataTable,
  type Column,
} from "@/core/components/data-table";
import { useServerTable } from "@/modules/hr/hooks/use-server-table";
import type { DateWindow, QueueParams } from "@/modules/hr/api/queue";
import { AutoOvertimeReview } from "@/modules/hr/components/auto-overtime-review";
import { OvertimeCorrectionDialog } from "@/modules/hr/components/overtime-correction-dialog";
import {
  type StatusFilterOption,
} from "@/core/components/status-filter";
import { formatDate, formatTimeManila } from "@/core/lib/format";
import { manilaToday } from "@/modules/hr/lib/manila-date";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import {
  useLeaveRequests,
  useApproveLeaveRequest,
  useRejectLeaveRequest,
  useCancelLeaveRequest,
  useReopenLeaveRequest,
} from "@/modules/hr/hooks/use-leave";
import {
  useHalfDayRequests,
  useApproveHalfDayRequest,
  useRejectHalfDayRequest,
  useReopenHalfDayRequest,
} from "@/modules/hr/hooks/use-half-day";
import {
  useEarlyOutRequests,
  useApproveEarlyOutRequest,
  useRejectEarlyOutRequest,
  useReopenEarlyOutRequest,
} from "@/modules/hr/hooks/use-early-out";
import {
  useOvertimeRequests,
  useApproveOvertimeRequest,
  useRejectOvertimeRequest,
  useCancelOvertimeRequest,
  useReopenOvertimeRequest,
} from "@/modules/hr/hooks/use-overtime";
import {
  useWorkRequests,
  useApproveWorkRequest,
  useRejectWorkRequest,
  useReopenWorkRequest,
} from "@/modules/hr/hooks/use-work-request";
import type {
  LeaveRequest,
  HalfDayRequest,
  EarlyOutRequest,
  OvertimeRequest,
  WorkRequest,
} from "@/modules/hr/types/leave";

// Pending / Approved / Rejected are the leave + half-day lifecycle states.
const REQUEST_STATUS_OPTIONS: StatusFilterOption[] = [
  { value: "all", label: "All" },
  { value: "pending", label: "Pending" },
  { value: "approved", label: "Approved" },
  { value: "rejected", label: "Rejected" },
];

/**
 * Rows carry decision buttons AND open the detail modal, so a press on a button must
 * not also count as a press on the row.
 */
function RowActions({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex justify-end gap-1.5" onClick={(e) => e.stopPropagation()}>
      {children}
    </div>
  );
}

type Period = "all" | "week" | "month" | "year" | "custom";

const PERIOD_OPTIONS = [
  { value: "all", label: "All time" },
  { value: "week", label: "This week" },
  { value: "month", label: "This month" },
  { value: "year", label: "This year" },
  { value: "custom", label: "Custom range…" },
];

/** Two digits, for building a 'Y-m-d' by hand. */
const p2 = (n: number) => String(n).padStart(2, "0");

/** Milliseconds back to the 'Y-m-d' the API filters on. */
const ymd = (ms: number) => new Date(ms).toISOString().slice(0, 10);

const DAY_MS = 86_400_000;

/**
 * The inclusive window a named period covers, on the MANILA calendar (plan §5.5b) — "this week"
 * has to mean the week it is in Manila, not the week it is in the reader's browser, or an HR
 * officer abroad gets a different queue than the one their colleague is looking at.
 *
 * The arithmetic runs in UTC on purpose. These are calendar dates with no time on them, and UTC
 * is the one zone where adding a day cannot land on 23:00 the same day.
 */
function periodWindow(period: Period, today: string): DateWindow {
  if (period === "all" || period === "custom") return {};

  const [y, m, d] = today.split("-").map(Number);

  if (period === "year") return { from: `${y}-01-01`, to: `${y}-12-31` };

  // Day 0 of the next month is the last day of this one.
  if (period === "month") {
    const last = new Date(Date.UTC(y, m, 0)).getUTCDate();
    return { from: `${y}-${p2(m)}-01`, to: `${y}-${p2(m)}-${p2(last)}` };
  }

  // Week — Monday through Sunday, the week the Philippine work week is counted in.
  const t = Date.UTC(y, m - 1, d);
  const monday = t - ((new Date(t).getUTCDay() + 6) % 7) * DAY_MS;
  return { from: ymd(monday), to: ymd(monday + 6 * DAY_MS) };
}

/**
 * The empty message a queue should show. "No leave requests yet" under a period filter is a
 * false statement about the whole queue — the rows exist, they are just outside the window, and
 * a reader told there are none has no reason to widen it.
 */
function emptyText(dateWindow: DateWindow, whole: string, filtered: string): string {
  return dateWindow.from || dateWindow.to ? filtered : whole;
}

/** What the window reads as under the picker, so a narrowed queue always says what narrowed it. */
function windowLabel({ from, to }: DateWindow): string | null {
  if (from && to) return `${formatDate(from)} – ${formatDate(to)}`;
  if (from) return `${formatDate(from)} onwards`;
  if (to) return `Up to ${formatDate(to)}`;
  return null;
}

/**
 * Filters every queue on the page by the date the request is FOR — the day off, the shift, the
 * overtime — rather than the day it was filed, because that is the date each table shows and
 * the one a payroll cutoff is drawn around. Leave spans days, so a leave is in the window when
 * it OVERLAPS it: one running Sep 28 to Oct 2 belongs to both months, not only to September.
 */
function PeriodFilter({
  period,
  onPeriodChange,
  from,
  to,
  onFromChange,
  onToChange,
  dateWindow,
  className,
}: {
  period: Period;
  onPeriodChange: (period: Period) => void;
  from: string;
  to: string;
  onFromChange: (value: string) => void;
  onToChange: (value: string) => void;
  dateWindow: DateWindow;
  className?: string;
}) {
  const label = windowLabel(dateWindow);

  return (
    <div className={cn("flex flex-wrap items-center gap-3", className)}>
      <label htmlFor="req-period" className="text-caption font-medium text-muted-foreground">
        Dated
      </label>
      <SimpleSelect
        id="req-period"
        className="w-44"
        value={period}
        onValueChange={(v) => onPeriodChange(v as Period)}
        options={PERIOD_OPTIONS}
      />

      {period === "custom" && (
        <>
          {/*
           * Each bound caps the other, so the pair cannot be put the wrong way round — a
           * backwards range is not an error worth reporting when it can simply be unpickable.
           * Either half may be left empty: that is an open-ended window, which the API takes.
           */}
          <DatePicker
            aria-label="Dated from"
            className="w-40"
            placeholder="From"
            value={from}
            max={to || undefined}
            onChange={onFromChange}
          />
          <span className="text-muted-foreground">–</span>
          <DatePicker
            aria-label="Dated up to"
            className="w-40"
            placeholder="To"
            value={to}
            min={from || undefined}
            onChange={onToChange}
          />
        </>
      )}

      {label && <span className="text-caption text-muted-foreground">{label}</span>}
    </div>
  );
}

type RequestTab = "leave" | "half" | "early" | "overtime" | "work";

/**
 * The description is the point of the card: five queue names alone do not say which of them a
 * given request belongs in, and half-day / early-out especially are told apart by what they do
 * to pay, not by their names.
 */
const REQUEST_TABS: { value: RequestTab; title: string; description: string }[] = [
  {
    value: "leave",
    title: "Leave",
    description: "Whole days off, filed against their leave credits.",
  },
  {
    value: "half",
    title: "Half-Day",
    description: "Half a day off. Always unpaid — 0.5 day deducted.",
  },
  {
    value: "early",
    title: "Early-Out",
    description: "Undertime: leaving before the schedule ends. Unpaid.",
  },
  {
    value: "overtime",
    title: "Overtime",
    description: "Hours past the schedule. Approving adds pay.",
  },
  {
    value: "work",
    title: "Work",
    description: "Time-ins on a rest day, or on a suspended day.",
  },
];

/**
 * One row, pending only — the row itself is thrown away and `total` is the count. These run on
 * the same query keys the tables use, so a decision made on one tab drops that tab's badge
 * through the invalidation the mutation already does; there is no second path to keep in step.
 *
 * They carry the date window too. A card reading "3 pending" over a table showing one, because
 * the card counted the whole queue and the table counted the month, is a number that has to be
 * mistrusted from then on.
 */
function pendingParams(dateWindow: DateWindow): QueueParams {
  return { page: 1, per_page: 1, status: "pending", ...dateWindow };
}

/** `undefined` while a count is still in flight, so a loading queue doesn't read as an empty one. */
function usePendingCounts(dateWindow: DateWindow): Record<RequestTab, number | undefined> {
  const params = pendingParams(dateWindow);
  const leave = useLeaveRequests(params);
  const half = useHalfDayRequests(params);
  const early = useEarlyOutRequests(params);
  const overtime = useOvertimeRequests(params);
  const work = useWorkRequests(params);

  return {
    leave: leave.data?.total,
    half: half.data?.total,
    early: early.data?.total,
    overtime: overtime.data?.total,
    work: work.data?.total,
  };
}

/**
 * The Attendance Summary tab cards, applied to the five request queues: a bordered card each,
 * carrying the queue's name, what belongs in it, and how many of them are waiting on a decision.
 * The active card is the brand-tinted one.
 *
 * They are buttons rather than links because these queues are one route, not five — but they
 * carry the tab roles, so a screen reader is told the same thing the border says.
 */
function RequestTabs({
  value,
  onChange,
  pending,
  className,
}: {
  value: RequestTab;
  onChange: (tab: RequestTab) => void;
  pending: Record<RequestTab, number | undefined>;
  className?: string;
}) {
  return (
    <div
      role="tablist"
      aria-label="Request type"
      className={cn("grid gap-4 sm:grid-cols-2 lg:grid-cols-3", className)}
    >
      {REQUEST_TABS.map((t) => {
        const active = value === t.value;
        const count = pending[t.value];

        return (
          <button
            key={t.value}
            type="button"
            role="tab"
            aria-selected={active}
            onClick={() => onChange(t.value)}
            className={cn(
              "rounded-card border px-5 py-4 text-left transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50",
              active
                ? "border-brand bg-brand-subtle"
                : "border-border bg-card hover:border-brand/60",
            )}
          >
            <div className="flex items-center justify-between gap-3">
              <p className="font-bold text-foreground">{t.title}</p>
              {/*
               * Amber, the colour every Pending badge on the page below already uses. A queue
               * with nothing waiting shows no badge at all: an empty count rendered as "0" is
               * one more number to read on a card that has nothing to say.
               */}
              {count ? (
                <span className="shrink-0 rounded-full bg-warning px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap text-white">
                  {count} pending
                </span>
              ) : null}
            </div>
            <p className="mt-1 text-caption text-muted-foreground">{t.description}</p>
          </button>
        );
      })}
    </div>
  );
}

export default function TimeLeavePage() {
  const [tab, setTab] = useState<RequestTab>("leave");
  const [period, setPeriod] = useState<Period>("all");
  const [customFrom, setCustomFrom] = useState("");
  const [customTo, setCustomTo] = useState("");

  const dateWindow = useMemo(
    () =>
      period === "custom"
        ? { from: customFrom || undefined, to: customTo || undefined }
        : periodWindow(period, manilaToday()),
    [period, customFrom, customTo],
  );

  const pending = usePendingCounts(dateWindow);

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Leave & Requests"
        subtitle="Review and decide on leave, half-day, early-out, overtime and work requests."
      />

      {/* Automation explainer */}
      <div className="mt-6 flex items-start gap-3 rounded-card border border-brand/40 bg-brand-subtle px-4 py-3">
        <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand text-brand-foreground">
          <Zap className="size-4" fill="currentColor" />
        </span>
        <div>
          <p className="text-sm font-semibold text-foreground">Leave automation is live</p>
          <p className="text-caption text-muted-foreground">
            When an employee submits a request it appears here — approving updates
            their balance the moment you decide.
          </p>
        </div>
      </div>

      {/* Above the cards, because it scopes the counts on them as well as the table below. */}
      <PeriodFilter
        period={period}
        onPeriodChange={setPeriod}
        from={customFrom}
        to={customTo}
        onFromChange={setCustomFrom}
        onToChange={setCustomTo}
        dateWindow={dateWindow}
        className="mt-6"
      />

      <RequestTabs value={tab} onChange={setTab} pending={pending} className="mt-4" />

      {/*
       * One queue mounted at a time — the four hidden ones stop fetching and stop paging in the
       * background, so switching tabs asks the server for the queue being looked at and nothing
       * else. Each keeps its own filter and page, and starts fresh when you come back to it.
       */}
      {tab === "leave" ? (
        <LeaveSection dateWindow={dateWindow} />
      ) : tab === "half" ? (
        <HalfDaySection dateWindow={dateWindow} />
      ) : tab === "early" ? (
        <EarlyOutSection dateWindow={dateWindow} />
      ) : tab === "overtime" ? (
        <OvertimeSection dateWindow={dateWindow} />
      ) : (
        <WorkRequestSection dateWindow={dateWindow} />
      )}
    </div>
  );
}

function LeaveSection({ dateWindow }: { dateWindow: DateWindow }) {
  // Server-paged. This queue holds every request of its kind anyone has ever filed.
  const table = useServerTable(10, dateWindow);
  const { data, isLoading, isError, isFetching } = useLeaveRequests(table.params);
  const requests = data?.items ?? [];
  const approveMut = useApproveLeaveRequest();
  const rejectMut = useRejectLeaveRequest();
  const cancelMut = useCancelLeaveRequest();
  const reopenMut = useReopenLeaveRequest();
  const [review, setReview] = useState<{ mode: "approve" | "reject"; r: LeaveRequest } | null>(null);
  const [cancelTarget, setCancelTarget] = useState<LeaveRequest | null>(null);
  // The status filter moved into the table, and from there into the query — filtering here
  // would only narrow the ten rows already on screen.
  const visible = requests;
  // Held by id, then read back from the live list, so deciding inside the modal
  // updates what the modal shows instead of leaving stale status on screen.
  const [detailId, setDetailId] = useState<number | null>(null);
  const detail = (requests ?? []).find((r) => r.id === detailId) ?? null;

  // HR may cancel an approved leave only before it starts (Manila day), matching
  // the backend rule. Dates are stored as Y-m-d, so a string compare is safe.
  const todayManila = new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" });
  const canCancel = (r: LeaveRequest) => r.status === "approved" && r.start_date > todayManila;

  async function onConfirm(reason: string) {
    if (!review) return;
    const { mode, r } = review;
    try {
      if (mode === "approve") {
        await approveMut.mutateAsync(r.id);
        toast.success(`Approved ${r.employee.name}'s leave`);
      } else {
        await rejectMut.mutateAsync({ id: r.id, reason });
        toast.error(`Declined ${r.employee.name}'s leave`);
      }
    } catch {
      toast.error("Action failed. Please try again.");
    }
  }

  async function onCancelConfirm(reason: string) {
    if (!cancelTarget) return;
    try {
      await cancelMut.mutateAsync({ id: cancelTarget.id, reason });
      toast.success(`Cancelled ${cancelTarget.employee.name}'s leave`);
    } catch {
      toast.error("Couldn't cancel. Please try again.");
      // Rethrow so the dialog stays open for a retry.
      throw new Error("cancel failed");
    }
  }

  const busyId =
    (approveMut.isPending && approveMut.variables) ||
    (rejectMut.isPending && rejectMut.variables?.id) ||
    null;

  const dates = (r: LeaveRequest) =>
    r.start_date === r.end_date
      ? formatDate(r.start_date)
      : `${formatDate(r.start_date)} – ${formatDate(r.end_date)}`;

  /** The same decisions on the row and in the detail modal. */
  function leaveActions(r: LeaveRequest) {
    if (r.status === "pending") {
      return (
        <>
          <Button
            size="sm"
            variant="success"
            disabled={busyId === r.id}
            onClick={() => setReview({ mode: "approve", r })}
          >
            <Check />
            Approve
          </Button>
          <Button
            size="sm"
            variant="destructive"
            disabled={busyId === r.id}
            onClick={() => setReview({ mode: "reject", r })}
          >
            <X />
            Reject
          </Button>
        </>
      );
    }
    return (
      <>
        {canCancel(r) ? (
          <Button
            size="sm"
            variant="outline"
            className="border-danger text-danger hover:bg-danger/10 hover:text-danger"
            disabled={cancelMut.isPending && cancelMut.variables?.id === r.id}
            onClick={() => setCancelTarget(r)}
          >
            <Ban />
            Cancel
          </Button>
        ) : null}
        <ReopenAction
          subject={`${r.employee.name}'s ${r.leave_type.name}`}
          consequence={
            r.status === "approved"
              ? `The ${r.days} day(s) deducted from their balance will be credited back.`
              : undefined
          }
          onReopen={(reason) => reopenMut.mutateAsync({ id: r.id, reason })}
        />
      </>
    );
  }

  const columns: Column<LeaveRequest>[] = [
    {
      header: "Employee",
      cell: (r) => <EmployeeCell employee={r.employee} />,
    },
    { header: "Type", cell: (r) => r.leave_type.name },
    { header: "Dates", cell: (r) => dates(r) },
    { header: "Days", cell: (r) => r.days },
    {
      header: "Reason",
      cell: (r) => <span className="text-muted-foreground">{r.reason}</span>,
    },
    { header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
    {
      header: "Action",
      align: "right",
      cell: (r) => <RowActions>{leaveActions(r)}</RowActions>,
    },
  ];

  const detailFields: DetailField[] = detail
    ? [
        { label: "Leave type", value: detail.leave_type.name },
        { label: "Dates", value: dates(detail) },
        {
          label: "Days",
          value: detail.is_half_day
            ? `${detail.days} (half day · ${detail.half_day_period ?? "—"})`
            : detail.days,
        },
        {
          label: "Deducted from",
          value:
            detail.status === "approved"
              ? "Their leave credits"
              : detail.status === "cancelled"
                ? "Credits returned"
                : undefined,
        },
      ]
    : [];

  return (
    <>
      <div className="mt-8">
        <h2 className="text-h3 font-bold text-foreground mb-8">Leave Requests</h2>
        {isLoading ? (
          <p className="mt-4 text-sm text-muted-foreground">Loading requests…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">Could not load leave requests.</p>
        ) : (
          <DataTable
            columns={columns}
            rows={visible}
            rowKey={(r) => r.id}
            onRowClick={(r) => setDetailId(r.id)}
            pageSize={table.pageSize}
            searchPlaceholder="Search employee, type, reason…"
            empty={emptyText(
              dateWindow,
              "No leave requests yet.",
              "No leave falls in this period.",
            )}
            // `needsAction` and `getSearchText` are gone deliberately: both worked on the
            // rows in hand. The query sorts pending first and searches across the whole
            // queue, so neither can be answered from a page any more.
            server={table.serverProps(data?.total ?? 0, isFetching, REQUEST_STATUS_OPTIONS)}
          />
        )}
      </div>

      <ReviewDialog
        open={!!review}
        onOpenChange={(o) => !o && setReview(null)}
        mode={review?.mode ?? "approve"}
        subject={review ? `${review.r.employee.name}'s ${review.r.leave_type.name}` : ""}
        onConfirm={onConfirm}
      />

      <CancelLeaveDialog
        open={!!cancelTarget}
        onOpenChange={(o) => !o && setCancelTarget(null)}
        subject={
          cancelTarget
            ? `${cancelTarget.employee.name}'s ${cancelTarget.leave_type.name}`
            : undefined
        }
        onConfirm={onCancelConfirm}
      />

      {detail && (
        <RequestDetailDialog
          open
          onOpenChange={(o) => !o && setDetailId(null)}
          title={detail.leave_type.name}
          status={detail.status}
          employee={detail.employee}
          submitted={detail.created_at}
          fields={detailFields}
          note={{ label: "Reason", text: detail.reason }}
          decision={{
            by: detail.reviewed_by,
            at: detail.reviewed_at,
            note: detail.review_note,
          }}
          actions={leaveActions(detail)}
        >
          {detail.cancelled_at && (
            <div className="space-y-1.5">
              <p className="text-label font-semibold uppercase tracking-wide text-brand">
                Cancelled
              </p>
              <p className="text-caption text-muted-foreground">
                {detail.cancelled_by ? `${detail.cancelled_by} · ` : ""}
                {formatDate(detail.cancelled_at)}
              </p>
              {detail.cancel_reason && (
                <p className="whitespace-pre-wrap rounded-lg border border-border bg-muted/40 px-3.5 py-3 text-sm text-foreground">
                  {detail.cancel_reason}
                </p>
              )}
            </div>
          )}
        </RequestDetailDialog>
      )}

    </>
  );
}

// ---- Work requests (filed by pressing Time in on a day nobody was expected in on) ----

/**
 * `expired` is in the filter and nowhere else: a pending request goes stale 24h after the punch
 * it would stamp, and HR needs to be able to find the ones that lapsed — a request that silently
 * vanishes from every view is indistinguishable from one that was never filed.
 */
const WORK_REQUEST_STATUS_OPTIONS: StatusFilterOption[] = [
  { value: "all", label: "All" },
  { value: "pending", label: "Pending" },
  { value: "approved", label: "Approved" },
  { value: "rejected", label: "Rejected" },
  { value: "expired", label: "Expired" },
];

/** What the day was, in the words the row and every toast use. */
function dayLabel(r: WorkRequest): string {
  return r.type === "suspension" ? "suspended day" : "rest day";
}

/** How the day pays once worked — the only thing the two types price differently. */
function payLabel(r: WorkRequest) {
  return r.type === "suspension" ? (
    <span className="text-success">Paid · ordinary day</span>
  ) : (
    <span className="text-success">Paid · rest-day premium</span>
  );
}

function WorkRequestSection({ dateWindow }: { dateWindow: DateWindow }) {
  // Server-paged. This queue holds every request of its kind anyone has ever filed.
  const table = useServerTable(10, dateWindow);
  const { data, isLoading, isFetching } = useWorkRequests(table.params);
  const requests = data?.items ?? [];
  const approveMut = useApproveWorkRequest();
  const rejectMut = useRejectWorkRequest();
  const reopenMut = useReopenWorkRequest();
  const [review, setReview] = useState<{ mode: "approve" | "reject"; r: WorkRequest } | null>(null);
  // The status filter moved into the table, and from there into the query — filtering here
  // would only narrow the ten rows already on screen.
  const visible = requests;
  const [detailId, setDetailId] = useState<number | null>(null);
  const detail = (requests ?? []).find((r) => r.id === detailId) ?? null;

  async function onConfirm(reason: string) {
    if (!review) return;
    const { mode, r } = review;
    try {
      if (mode === "approve") {
        await approveMut.mutateAsync(r.id);
        toast.success(
          `Approved ${r.employee.name}'s work on the ${dayLabel(r)}`,
          `Timed in at ${formatTimeManila(r.requested_at)}, when they asked.`,
        );
      } else {
        await rejectMut.mutateAsync({ id: r.id, reason });
        toast.error(`Declined ${r.employee.name}'s request to work the ${dayLabel(r)}`);
      }
    } catch (err) {
      // Unlike the other four, this one has decisions that legitimately fail — an expired
      // request, or a time-in that appeared while HR was deciding — and each says why.
      const m = (err as { response?: { data?: { message?: string } } }).response?.data;
      toast.error(m?.message ?? "Action failed. Please try again.");
    }
  }

  const busyId =
    (approveMut.isPending && approveMut.variables) ||
    (rejectMut.isPending && rejectMut.variables?.id) ||
    null;

  function workRequestActions(r: WorkRequest) {
    if (r.status === "pending") {
      return (
        <>
          <Button size="sm" variant="success" disabled={busyId === r.id} onClick={() => setReview({ mode: "approve", r })}>
            <Check />
            Approve
          </Button>
          <Button size="sm" variant="destructive" disabled={busyId === r.id} onClick={() => setReview({ mode: "reject", r })}>
            <X />
            Reject
          </Button>
        </>
      );
    }
    // An expired or cancelled request has nothing to undo — reopening one would put a
    // stale punch back in front of HR as if it were still decidable.
    if (r.status !== "approved" && r.status !== "rejected") {
      return null;
    }
    return (
      <ReopenAction
        subject={`${r.employee.name}'s work on the ${dayLabel(r)}`}
        consequence={
          r.status === "approved"
            ? "The attendance this approval created is removed with it. Refused once the shift has been clocked out."
            : undefined
        }
        onReopen={(reason) => reopenMut.mutateAsync({ id: r.id, reason })}
      />
    );
  }

  const columns: Column<WorkRequest>[] = [
    {
      header: "Employee",
      cell: (r) => <EmployeeCell employee={r.employee} />,
    },
    // The two types are the same row with different reasons for existing, and the reason is
    // what decides the premium — so it is a column, not a footnote in the detail modal.
    {
      header: "Day",
      cell: (r) => (
        <div>
          <p className="font-medium text-foreground">{formatDate(r.work_date)}</p>
          <p className="text-caption text-muted-foreground capitalize">{dayLabel(r)}</p>
        </div>
      ),
    },
    {
      header: "Asked at",
      cell: (r) => (
        <span className="whitespace-nowrap font-medium text-foreground">
          {formatTimeManila(r.requested_at)}
        </span>
      ),
    },
    { header: "Pay", cell: (r) => payLabel(r) },
    {
      header: "Reason",
      cell: (r) => (
        <span className="text-muted-foreground">{r.reason ?? "—"}</span>
      ),
    },
    { header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
    {
      header: "Action",
      align: "right",
      cell: (r) => <RowActions>{workRequestActions(r)}</RowActions>,
    },
  ];

  return (
    <div className="mt-8">
      <h2 className="text-h3 font-bold text-foreground">Work Requests</h2>
      <p className="mt-1 text-caption text-muted-foreground">
        Filed when someone presses Time in on a day nobody was expected in on — a rest day, or a
        suspension you marked as needing permission. There is no form to fill in. Approving times
        them in at the moment they asked, not now; a rest day earns the rest-day premium and a
        suspended day is paid as an ordinary one. Requests lapse 24 hours after that moment.
      </p>
      <div className="mt-4">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading work requests…</p>
        ) : (
          <DataTable
            columns={columns}
            rows={visible}
            rowKey={(r) => r.id}
            onRowClick={(r) => setDetailId(r.id)}
            pageSize={table.pageSize}
            searchPlaceholder="Search employee, reason…"
            empty={emptyText(
              dateWindow,
              "No work requests yet. They appear here when someone times in on a rest day, or on a suspended day that needs your permission.",
              "No work requests dated in this period.",
            )}
            // `needsAction` and `getSearchText` are gone deliberately: both worked on the
            // rows in hand. The query sorts pending first and searches across the whole
            // queue, so neither can be answered from a page any more.
            server={table.serverProps(data?.total ?? 0, isFetching, WORK_REQUEST_STATUS_OPTIONS)}
          />
        )}
      </div>

      <ReviewDialog
        open={!!review}
        onOpenChange={(o) => !o && setReview(null)}
        mode={review?.mode ?? "approve"}
        subject={
          review
            ? `${review.r.employee.name}'s work on the ${dayLabel(review.r)} of ${formatDate(review.r.work_date)} (asked at ${formatTimeManila(review.r.requested_at)})`
            : ""
        }
        onConfirm={onConfirm}
      />

      {detail && (
        <RequestDetailDialog
          open
          onOpenChange={(o) => !o && setDetailId(null)}
          title="Work Request"
          status={detail.status}
          employee={detail.employee}
          submitted={detail.created_at}
          fields={[
            {
              label: detail.type === "suspension" ? "Suspended day" : "Rest day",
              value: formatDate(detail.work_date),
            },
            { label: "Times in at", value: formatTimeManila(detail.requested_at) },
            { label: "Pay", value: payLabel(detail) },
          ]}
          note={{ label: "Reason", text: detail.reason ?? "—" }}
          decision={{
            by: detail.reviewed_by,
            at: detail.reviewed_at,
            note: detail.review_note,
          }}
          actions={workRequestActions(detail)}
        />
      )}
    </div>
  );
}

// ---- Half-day requests (separate from leave, always unpaid) ----

function HalfDaySection({ dateWindow }: { dateWindow: DateWindow }) {
  // Server-paged. This queue holds every request of its kind anyone has ever filed.
  const table = useServerTable(10, dateWindow);
  const { data, isLoading, isFetching } = useHalfDayRequests(table.params);
  const requests = data?.items ?? [];
  const approveMut = useApproveHalfDayRequest();
  const rejectMut = useRejectHalfDayRequest();
  const reopenMut = useReopenHalfDayRequest();
  const [review, setReview] = useState<{ mode: "approve" | "reject"; r: HalfDayRequest } | null>(null);
  // The status filter moved into the table, and from there into the query — filtering here
  // would only narrow the ten rows already on screen.
  const visible = requests;
  const [detailId, setDetailId] = useState<number | null>(null);
  const detail = (requests ?? []).find((r) => r.id === detailId) ?? null;

  async function onConfirm(reason: string) {
    if (!review) return;
    const { mode, r } = review;
    try {
      if (mode === "approve") {
        await approveMut.mutateAsync(r.id);
        toast.success(`Approved ${r.employee.name}'s half-day`);
      } else {
        await rejectMut.mutateAsync({ id: r.id, reason });
        toast.error(`Declined ${r.employee.name}'s half-day`);
      }
    } catch {
      toast.error("Action failed. Please try again.");
    }
  }

  const busyId =
    (approveMut.isPending && approveMut.variables) ||
    (rejectMut.isPending && rejectMut.variables?.id) ||
    null;

  /**
   * The hours the employee still owes on a half day. Shown because approving one is a decision
   * about the OTHER half: null means their schedule cannot say what that half is, and the
   * platform will then decline to judge the day's lateness at all.
   */
  function workedWindow(r: HalfDayRequest) {
    if (!r.worked_window) {
      return (
        <span className="text-warning">
          No {r.period === "AM" ? "afternoon" : "morning"} hours set on their schedule
        </span>
      );
    }
    return (
      <span className="text-muted-foreground">
        {r.worked_window.start}–{r.worked_window.end}
      </span>
    );
  }

  function halfDayActions(r: HalfDayRequest) {
    if (r.status === "pending") {
      return (
        <>
          <Button size="sm" variant="success" disabled={busyId === r.id} onClick={() => setReview({ mode: "approve", r })}>
            <Check />
            Approve
          </Button>
          <Button size="sm" variant="destructive" disabled={busyId === r.id} onClick={() => setReview({ mode: "reject", r })}>
            <X />
            Reject
          </Button>
        </>
      );
    }
    return (
      <ReopenAction
        subject={`${r.employee.name}'s half-day`}
        consequence={
          r.status === "approved" ? "The 0.5-day deduction comes off this pay period." : undefined
        }
        onReopen={(reason) => reopenMut.mutateAsync({ id: r.id, reason })}
      />
    );
  }

  const columns: Column<HalfDayRequest>[] = [
    {
      header: "Employee",
      cell: (r) => <EmployeeCell employee={r.employee} />,
    },
    { header: "Date", cell: (r) => formatDate(r.work_date) },
    { header: "Half", cell: (r) => r.period },
    { header: "Still works", cell: (r) => workedWindow(r) },
    { header: "Pay", cell: () => <span className="text-danger">Unpaid · 0.5 day</span> },
    { header: "Reason", cell: (r) => <span className="text-muted-foreground">{r.reason}</span> },
    { header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
    {
      header: "Action",
      align: "right",
      cell: (r) => <RowActions>{halfDayActions(r)}</RowActions>,
    },
  ];

  return (
    <div className="mt-8">
      <h2 className="text-h3 font-bold text-foreground">Half-Day Requests</h2>
      <p className="mt-1 text-caption text-muted-foreground">
        Separate from leave — always unpaid. Approving deducts 0.5 day from that pay period.
      </p>
      <div className="mt-4">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading half-day requests…</p>
        ) : (
          <DataTable
            columns={columns}
            rows={visible}
            rowKey={(r) => r.id}
            onRowClick={(r) => setDetailId(r.id)}
            pageSize={table.pageSize}
            searchPlaceholder="Search employee, reason…"
            empty={emptyText(
              dateWindow,
              "No half-day requests yet.",
              "No half-day requests dated in this period.",
            )}
            // `needsAction` and `getSearchText` are gone deliberately: both worked on the
            // rows in hand. The query sorts pending first and searches across the whole
            // queue, so neither can be answered from a page any more.
            server={table.serverProps(data?.total ?? 0, isFetching, REQUEST_STATUS_OPTIONS)}
          />
        )}
      </div>

      <ReviewDialog
        open={!!review}
        onOpenChange={(o) => !o && setReview(null)}
        mode={review?.mode ?? "approve"}
        subject={review ? `${review.r.employee.name}'s half-day (${formatDate(review.r.work_date)} ${review.r.period})` : ""}
        onConfirm={onConfirm}
      />

      {detail && (
        <RequestDetailDialog
          open
          onOpenChange={(o) => !o && setDetailId(null)}
          title="Half-Day"
          status={detail.status}
          employee={detail.employee}
          submitted={detail.created_at}
          fields={[
            { label: "Date", value: formatDate(detail.work_date) },
            { label: "Half", value: detail.period },
            { label: "Still works", value: workedWindow(detail) },
            { label: "Pay", value: <span className="text-danger">Unpaid · 0.5 day</span> },
          ]}
          note={{ label: "Reason", text: detail.reason }}
          decision={{
            by: detail.reviewed_by,
            at: detail.reviewed_at,
            note: detail.review_note,
          }}
          actions={halfDayActions(detail)}
        />
      )}
    </div>
  );
}

// ---- Early-out requests (undertime — separate from leave, always unpaid) ----

function EarlyOutSection({ dateWindow }: { dateWindow: DateWindow }) {
  // Server-paged. This queue holds every request of its kind anyone has ever filed.
  const table = useServerTable(10, dateWindow);
  const { data, isLoading, isFetching } = useEarlyOutRequests(table.params);
  const requests = data?.items ?? [];
  const approveMut = useApproveEarlyOutRequest();
  const rejectMut = useRejectEarlyOutRequest();
  const reopenMut = useReopenEarlyOutRequest();
  const [review, setReview] = useState<{ mode: "approve" | "reject"; r: EarlyOutRequest } | null>(null);
  // The status filter moved into the table, and from there into the query — filtering here
  // would only narrow the ten rows already on screen.
  const visible = requests;
  const [detailId, setDetailId] = useState<number | null>(null);
  const detail = (requests ?? []).find((r) => r.id === detailId) ?? null;

  async function onConfirm(reason: string) {
    if (!review) return;
    const { mode, r } = review;
    try {
      if (mode === "approve") {
        await approveMut.mutateAsync(r.id);
        toast.success(`Approved ${r.employee.name}'s early-out`);
      } else {
        await rejectMut.mutateAsync({ id: r.id, reason });
        toast.error(`Declined ${r.employee.name}'s early-out`);
      }
    } catch {
      toast.error("Action failed. Please try again.");
    }
  }

  const busyId =
    (approveMut.isPending && approveMut.variables) ||
    (rejectMut.isPending && rejectMut.variables?.id) ||
    null;

  function earlyOutActions(r: EarlyOutRequest) {
    if (r.status === "pending") {
      return (
        <>
          <Button size="sm" variant="success" disabled={busyId === r.id} onClick={() => setReview({ mode: "approve", r })}>
            <Check />
            Approve
          </Button>
          <Button size="sm" variant="destructive" disabled={busyId === r.id} onClick={() => setReview({ mode: "reject", r })}>
            <X />
            Reject
          </Button>
        </>
      );
    }
    return (
      <ReopenAction
        subject={`${r.employee.name}'s early-out`}
        consequence={
          r.status === "approved" ? "The undertime deduction comes off this pay period." : undefined
        }
        onReopen={(reason) => reopenMut.mutateAsync({ id: r.id, reason })}
      />
    );
  }

  const columns: Column<EarlyOutRequest>[] = [
    {
      header: "Employee",
      cell: (r) => <EmployeeCell employee={r.employee} />,
    },
    { header: "Date", cell: (r) => formatDate(r.work_date) },
    { header: "Leaves at", cell: (r) => r.early_out_time },
    { header: "Pay", cell: () => <span className="text-danger">Unpaid · undertime</span> },
    { header: "Reason", cell: (r) => <span className="text-muted-foreground">{r.reason}</span> },
    { header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
    {
      header: "Action",
      align: "right",
      cell: (r) => <RowActions>{earlyOutActions(r)}</RowActions>,
    },
  ];

  return (
    <div className="mt-8">
      <h2 className="text-h3 font-bold text-foreground">Early-Out Requests</h2>
      <p className="mt-1 text-caption text-muted-foreground">
        Undertime — leaving before the scheduled end. Always unpaid; approving deducts
        the proportional undertime from that pay period.
      </p>
      <div className="mt-4">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading early-out requests…</p>
        ) : (
          <DataTable
            columns={columns}
            rows={visible}
            rowKey={(r) => r.id}
            onRowClick={(r) => setDetailId(r.id)}
            pageSize={table.pageSize}
            searchPlaceholder="Search employee, reason…"
            empty={emptyText(
              dateWindow,
              "No early-out requests yet.",
              "No early-out requests dated in this period.",
            )}
            // `needsAction` and `getSearchText` are gone deliberately: both worked on the
            // rows in hand. The query sorts pending first and searches across the whole
            // queue, so neither can be answered from a page any more.
            server={table.serverProps(data?.total ?? 0, isFetching, REQUEST_STATUS_OPTIONS)}
          />
        )}
      </div>

      <ReviewDialog
        open={!!review}
        onOpenChange={(o) => !o && setReview(null)}
        mode={review?.mode ?? "approve"}
        subject={review ? `${review.r.employee.name}'s early-out (${formatDate(review.r.work_date)} ${review.r.early_out_time})` : ""}
        onConfirm={onConfirm}
      />

      {detail && (
        <RequestDetailDialog
          open
          onOpenChange={(o) => !o && setDetailId(null)}
          title="Early-Out"
          status={detail.status}
          employee={detail.employee}
          submitted={detail.created_at}
          fields={[
            { label: "Date", value: formatDate(detail.work_date) },
            { label: "Leaves at", value: detail.early_out_time },
            { label: "Pay", value: <span className="text-danger">Unpaid · undertime</span> },
          ]}
          note={{ label: "Reason", text: detail.reason }}
          decision={{
            by: detail.reviewed_by,
            at: detail.reviewed_at,
            note: detail.review_note,
          }}
          actions={earlyOutActions(detail)}
        />
      )}
    </div>
  );
}

// ---- Overtime requests (hours BEYOND the schedule — approving ADDS pay) ----

function OvertimeSection({ dateWindow }: { dateWindow: DateWindow }) {
  // Server-paged. This queue holds every request of its kind anyone has ever filed.
  const table = useServerTable(10, dateWindow);
  const { data, isLoading, isFetching } = useOvertimeRequests(table.params);
  const requests = data?.items ?? [];
  const approveMut = useApproveOvertimeRequest();
  const rejectMut = useRejectOvertimeRequest();
  const cancelMut = useCancelOvertimeRequest();
  const reopenMut = useReopenOvertimeRequest();
  const [review, setReview] = useState<{
    mode: "approve" | "reject" | "cancel";
    r: OvertimeRequest;
  } | null>(null);
  // The status filter moved into the table, and from there into the query — filtering here
  // would only narrow the ten rows already on screen.
  const visible = requests;
  const [detailId, setDetailId] = useState<number | null>(null);
  const detail = (requests ?? []).find((r) => r.id === detailId) ?? null;

  async function onConfirm(reason: string) {
    if (!review) return;
    const { mode, r } = review;
    try {
      if (mode === "approve") {
        await approveMut.mutateAsync(r.id);
        toast.success(`Approved ${r.employee.name}'s overtime`);
      } else if (mode === "cancel") {
        await cancelMut.mutateAsync({ id: r.id, reason });
        toast.success(`Cancelled ${r.employee.name}'s overtime`);
      } else {
        await rejectMut.mutateAsync({ id: r.id, reason });
        toast.error(`Declined ${r.employee.name}'s overtime`);
      }
    } catch {
      toast.error("Action failed. Please try again.");
    }
  }

  const busyId =
    (approveMut.isPending && approveMut.variables) ||
    (rejectMut.isPending && rejectMut.variables?.id) ||
    (cancelMut.isPending && cancelMut.variables?.id) ||
    null;

  function overtimeActions(r: OvertimeRequest) {
    if (r.status === "pending") {
      return (
        <>
          <Button size="sm" variant="success" disabled={busyId === r.id} onClick={() => setReview({ mode: "approve", r })}>
            <Check />
            Approve
          </Button>
          <Button size="sm" variant="destructive" disabled={busyId === r.id} onClick={() => setReview({ mode: "reject", r })}>
            <X />
            Reject
          </Button>
        </>
      );
    }
    return (
      <>
        {r.status === "approved" ? (
          // An approved request is no longer frozen: HR can undo overtime
          // that isn't going to happen, before payroll pays it.
          <Button size="sm" variant="outline" disabled={busyId === r.id} onClick={() => setReview({ mode: "cancel", r })}>
            <X />
            Cancel
          </Button>
        ) : null}
        <ReopenAction
          subject={`${r.employee.name}'s overtime`}
          consequence={
            r.status === "approved"
              ? `The ${r.hours.toFixed(1)} overtime hours stop counting toward pay.`
              : undefined
          }
          onReopen={(reason) => reopenMut.mutateAsync({ id: r.id, reason })}
        />
      </>
    );
  }

  const [filingFor, setFilingFor] = useState(false);

  const columns: Column<OvertimeRequest>[] = [
    {
      header: "Employee",
      cell: (r) => <EmployeeCell employee={r.employee} />,
    },
    { header: "Date", cell: (r) => formatDate(r.work_date) },
    {
      header: "Window",
      cell: (r) => (r.time_from && r.time_to ? `${r.time_from}–${r.time_to}` : "—"),
    },
    { header: "Hours", cell: (r) => `${r.hours.toFixed(1)} hrs` },
    { header: "Pay", cell: () => <span className="text-success">Paid · overtime</span> },
    {
      header: "Reason",
      cell: (r) => (
        <span className="text-muted-foreground">
          {r.reason}
          {/*
           * Item 10 — a request HR filed on someone's behalf must say so. Without it the row is
           * indistinguishable from one the employee sent, which credits the wrong person.
           */}
          {r.filed_by && <span className="block text-xs italic">Filed by {r.filed_by}</span>}
        </span>
      ),
    },
    { header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
    {
      header: "Action",
      align: "right",
      cell: (r) => <RowActions>{overtimeActions(r)}</RowActions>,
    },
  ];

  return (
    <div className="mt-8">
      {/*
       * Overtime the auto-pay cap held back, shown ABOVE the request table because the
       * table below is where it gets resolved: approving a request for that date releases it.
       */}
      <AutoOvertimeReview />

      <OvertimeCorrectionDialog open={filingFor} onOpenChange={setFilingFor} />

      <div className="mt-8 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-h3 font-bold text-foreground">Overtime Requests</h2>
          <p className="mt-1 max-w-2xl text-caption text-muted-foreground">
            Hours worked beyond the schedule. Approving ADDS pay for that period, at the
            company&apos;s overtime rate for that day (regular / rest day / special day / regular
            holiday).
          </p>
        </div>
        {/*
         * Item 10 — the correction path for overtime that was worked but never filed. Employees
         * cannot back-date their own, so without this the only remedies were editing the
         * attendance record or paying it outside the system.
         */}
        <Button variant="outline" onClick={() => setFilingFor(true)}>
          <CalendarPlus />
          File for an employee
        </Button>
      </div>
      <div className="mt-4">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading overtime requests…</p>
        ) : (
          <DataTable
            columns={columns}
            rows={visible}
            rowKey={(r) => r.id}
            onRowClick={(r) => setDetailId(r.id)}
            pageSize={table.pageSize}
            searchPlaceholder="Search employee, reason…"
            empty={emptyText(
              dateWindow,
              "No overtime requests yet.",
              "No overtime requests dated in this period.",
            )}
            // `needsAction` and `getSearchText` are gone deliberately: both worked on the
            // rows in hand. The query sorts pending first and searches across the whole
            // queue, so neither can be answered from a page any more.
            server={table.serverProps(data?.total ?? 0, isFetching, REQUEST_STATUS_OPTIONS)}
          />
        )}
      </div>

      <ReviewDialog
        open={!!review && review.mode !== "cancel"}
        onOpenChange={(o) => !o && setReview(null)}
        mode={review?.mode === "reject" ? "reject" : "approve"}
        subject={review ? `${review.r.employee.name}'s overtime (${formatDate(review.r.work_date)} · ${review.r.hours.toFixed(1)} hrs)` : ""}
        onConfirm={onConfirm}
      />

      {/*
       * Cancelling an APPROVED request needs its own reason prompt. The shared
       * ReviewDialog only knows approve/reject and lives in core/, so reuse the HR one.
       */}
      <CancelLeaveDialog
        open={review?.mode === "cancel"}
        onOpenChange={(o) => !o && setReview(null)}
        title="Cancel Overtime Request"
        subject={review ? `${review.r.employee.name}'s overtime (${formatDate(review.r.work_date)} · ${review.r.hours.toFixed(1)} hrs)` : ""}
        onConfirm={onConfirm}
      />

      {detail && (
        <RequestDetailDialog
          open
          onOpenChange={(o) => !o && setDetailId(null)}
          title="Overtime"
          status={detail.status}
          employee={detail.employee}
          submitted={detail.created_at}
          fields={[
            { label: "Date", value: formatDate(detail.work_date) },
            {
              label: "Window",
              value:
                detail.time_from && detail.time_to
                  ? `${detail.time_from} – ${detail.time_to}`
                  : undefined,
            },
            { label: "Hours", value: `${detail.hours.toFixed(1)} hrs` },
            { label: "Pay", value: <span className="text-success">Paid · overtime</span> },
          ]}
          note={{ label: "Reason", text: detail.reason }}
          decision={{
            by: detail.reviewed_by,
            at: detail.reviewed_at,
            note: detail.review_note,
          }}
          actions={overtimeActions(detail)}
        />
      )}
    </div>
  );
}
