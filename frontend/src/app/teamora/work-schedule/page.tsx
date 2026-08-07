"use client";

import { useState, useMemo } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { cn } from "@/lib/utils";
import { PageHeader } from "@/modules/hr/components/page-header";
import { StatCard } from "@/core/components/stat-card";
import { StatusBadge } from "@/core/components/status-badge";
import { Button } from "@/components/ui/button";
import { notify as toast } from "@/core/lib/notify";
import { formatDate, formatDateTime } from "@/core/lib/format";
import {
  RequestDetailDialog,
  type DetailField,
} from "@/modules/hr/components/request-detail-dialog";
import { LeaveBalanceCard } from "@/modules/hr/components/leave-balance-card";
import { LeaveTypesListCard } from "@/modules/hr/components/leave-types-list-card";
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
import { useHalfDayRequests } from "@/modules/hr/hooks/use-half-day";
import { useEarlyOutRequests } from "@/modules/hr/hooks/use-early-out";
import { useOvertimeRequests } from "@/modules/hr/hooks/use-overtime";

type RequestTab = "leave" | "half" | "early" | "overtime";

// Fixed rows per page — deliberately not user-selectable (see DataTable).
const PAGE_SIZE = 10;

const REQUEST_TITLES: Record<RequestTab, string> = {
  leave: "Request Leave",
  half: "Request Half-Day",
  early: "Request Early-Out",
  overtime: "Request overtime",
};

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
  date: string;
  status: string;
  at: string;
  /** The employee's own words when filing. */
  reason: string;
  /** HR's verdict — `note` is the decline reason on a rejected request. */
  decision: { at: string | null; by?: string | null; note: string | null };
  /** The label/value rows shown in the detail modal. */
  fields: DetailField[];
  /** Only leave can be cancelled, so only leave fills this in. */
  cancelled: { at: string; by: string | null; reason: string | null } | null;
  /** Employees can cancel only their own still-pending leave. */
  cancellable: boolean;
}

/**
 * Figma "segmented-toggle": a light-gray track holding three equal pills; the
 * active one is a white, subtly-shadowed pill with bold ink text. Picks which
 * kind of time-off request the single card shows.
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
  const cancelMut = useCancelLeaveRequest();
  const [tab, setTab] = useState<RequestTab>("leave");
  const [detail, setDetail] = useState<HistItem | null>(null);
  const [cancelTarget, setCancelTarget] = useState<HistItem | null>(null);
  const [page, setPage] = useState(1);

  const mode = summary?.mode ?? "not_shared";
  const balances = useMemo(() => summary?.balances ?? [], [summary]);
  const pool = summary?.pool ?? null;

  // Shared: one pool card + the type names. Not-shared: a single Leave Types
  // card listing every paid type with its own credits (no featured card).
  const paidCodes = useMemo(
    () => new Set((types ?? []).filter((t) => t.is_paid).map((t) => t.code)),
    [types],
  );

  const listItems =
    mode === "shared"
      ? (types ?? [])
          .filter((t) => t.is_paid)
          .map((t) => ({ key: `t${t.id}`, name: t.name, remaining: 0, entitled: 0 }))
      : balances
          .filter((b) => paidCodes.has(b.leave_type.code))
          .map((b) => ({
            key: `b${b.id}`,
            name: b.leave_type.name,
            remaining: b.remaining_days,
            entitled: b.entitled_days,
          }));

  const pendingCount =
    (requests ?? []).filter((r) => r.status === "pending").length +
    (halfDays ?? []).filter((h) => h.status === "pending").length +
    (earlyOuts ?? []).filter((e) => e.status === "pending").length +
    (overtimes ?? []).filter((o) => o.status === "pending").length;

  // Combined leave + half-day history, newest first.
  const history: HistItem[] = [
    ...(requests ?? []).map((l) => {
      const dates =
        l.start_date === l.end_date
          ? formatDate(l.start_date)
          : `${formatDate(l.start_date)} – ${formatDate(l.end_date)}`;
      return {
        key: `l${l.id}`,
        id: l.id,
        kind: "leave" as const,
        title: l.leave_type.name,
        date: dates,
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
      date: `${formatDate(h.work_date)} · Unpaid`,
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
      cancelled: null,
      cancellable: false,
    })),
    ...(earlyOuts ?? []).map((e) => ({
      key: `e${e.id}`,
      id: e.id,
      kind: "early" as const,
      title: `Early-out (${e.early_out_time})`,
      date: `${formatDate(e.work_date)} · Unpaid`,
      status: e.status,
      at: e.created_at,
      reason: e.reason,
      decision: { at: e.reviewed_at, by: e.reviewed_by, note: e.review_note },
      fields: [
        { label: "Date", value: formatDate(e.work_date) },
        { label: "Leaving at", value: e.early_out_time },
        { label: "Pay", value: <span className="text-danger">Unpaid · undertime</span> },
      ],
      cancelled: null,
      cancellable: false,
    })),
    ...(overtimes ?? []).map((o) => ({
      key: `o${o.id}`,
      id: o.id,
      kind: "overtime" as const,
      title: `Overtime (${o.hours.toFixed(1)} hrs)`,
      date: `${formatDate(o.work_date)} · Paid`,
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
      cancelled: null,
      cancellable: false,
    })),
  ].sort((a, b) => +new Date(b.at) - +new Date(a.at));

  const totalPages = Math.max(1, Math.ceil(history.length / PAGE_SIZE));
  const safePage = Math.min(page, totalPages);
  const pageHistory = useMemo(
    () => history.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE),
    [history, safePage],
  );

  async function handleCancel(reason: string) {
    if (!cancelTarget) return;
    try {
      await cancelMut.mutateAsync({ id: cancelTarget.id, reason });
      toast.success("Request cancelled", "Your leave request has been cancelled.");
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

      {/* Balance summary: (pool in shared) · leave-types list · pending */}
      <div
        className={cn(
          "mt-6 grid grid-cols-1",
          // Not-shared: mirror the request row below (lg:grid-cols-2 + gap-6) so the
          // Leave Types card is exactly the request-form card's width; Pending sits
          // content-sized in the second cell. Shared: three left-packed cards.
          mode === "shared"
            ? "gap-4 sm:grid-cols-[18rem_20rem_auto] sm:justify-start"
            : "gap-6 lg:grid-cols-2",
        )}
      >
        {mode === "shared" &&
          (pool ? (
            <LeaveBalanceCard
              label="Leave Pool"
              entitled={pool.entitled_days}
              remaining={pool.remaining_days}
              used={pool.used_days}
              centered
            />
          ) : (
            <StatCard label="Leave Pool" value="—" subNote="No pool for this year" />
          ))}

        <LeaveTypesListCard
          items={listItems}
          showCredits={mode !== "shared"}
          note={
            mode === "shared"
              ? "All leave types draw from your shared pool."
              : undefined
          }
        />

        <StatCard
          label="Pending Requests"
          value={pendingCount}
          subNote="Awaiting HR decision"
          className="justify-self-start min-w-[18rem]"
        />
      </div>

      {/* Left: one tabbed request card · Right: calendar + history */}
      <div className="mt-6 grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
        <div className="self-start rounded-card border border-border bg-card p-6">
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

        <div className="space-y-6">
          <TimeOffCalendar
            leaves={requests ?? []}
            halfDays={halfDays ?? []}
            earlyOuts={earlyOuts ?? []}
          />

          <div className="rounded-card border border-border bg-card p-6">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-h3 font-bold text-foreground">My Request History</h2>
            </div>
            <div className="divide-y divide-border">
              {history.length === 0 ? (
                <p className="py-6 text-sm text-muted-foreground">No requests yet.</p>
              ) : (
                // Every row opens the full detail, so a decline reason is one tap away
                // and so is everything else the employee filed.
                pageHistory.map((r) => (
                  // A div, not a button: the Cancel action lives inside the row and
                  // nesting one button in another is invalid markup.
                  <div
                    key={r.key}
                    role="button"
                    tabIndex={0}
                    onClick={() => setDetail(r)}
                    onKeyDown={(e) => {
                      if (e.key === "Enter" || e.key === " ") {
                        e.preventDefault();
                        setDetail(r);
                      }
                    }}
                    className="flex w-full cursor-pointer items-center justify-between gap-3 py-3 text-left transition-colors hover:bg-muted/40"
                  >
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-foreground">{r.title}</p>
                      <p
                        className={cn(
                          "text-caption",
                          r.status === "rejected" ? "text-danger" : "text-muted-foreground",
                        )}
                      >
                        {r.date}
                        {r.status === "rejected" ? " · Tap to see why" : ""}
                      </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                      <StatusBadge status={r.status} />
                      {r.cancellable && (
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
                      )}
                    </div>
                  </div>
                ))
              )}
            </div>
            {history.length > 0 && (
              <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                <div className="text-caption text-muted-foreground">
                  Showing {(safePage - 1) * PAGE_SIZE + 1}–
                  {Math.min(safePage * PAGE_SIZE, history.length)} of {history.length}
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
        subject={cancelTarget ? `${cancelTarget.title} · ${cancelTarget.date}` : undefined}
        onConfirm={handleCancel}
      />
    </div>
  );
}
