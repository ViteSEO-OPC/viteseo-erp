"use client";

import { useState } from "react";
import { Zap, Check, X, Ban } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

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
import { AutoOvertimeReview } from "@/modules/hr/components/auto-overtime-review";
import {
  StatusFilter,
  filterByStatus,
  type StatusFilterOption,
} from "@/core/components/status-filter";
import { formatDate, formatTimeManila } from "@/core/lib/format";
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
  useRestDayRequests,
  useApproveRestDayRequest,
  useRejectRestDayRequest,
  useReopenRestDayRequest,
} from "@/modules/hr/hooks/use-rest-day";
import type {
  LeaveRequest,
  HalfDayRequest,
  EarlyOutRequest,
  OvertimeRequest,
  RestDayRequest,
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

export default function TimeLeavePage() {
  const { data: requests, isLoading, isError } = useLeaveRequests();
  const approveMut = useApproveLeaveRequest();
  const rejectMut = useRejectLeaveRequest();
  const cancelMut = useCancelLeaveRequest();
  const reopenMut = useReopenLeaveRequest();
  const [review, setReview] = useState<{ mode: "approve" | "reject"; r: LeaveRequest } | null>(null);
  const [cancelTarget, setCancelTarget] = useState<LeaveRequest | null>(null);
  const [status, setStatus] = useState("all");
  const visible = filterByStatus(requests ?? [], status);
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
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Work Schedule"
        subtitle="Review and decide on leave, half-day, early-out, overtime and rest-day work requests."
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

      <div className="mt-6">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading requests…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">Could not load leave requests.</p>
        ) : (
          <DataTable
            columns={columns}
            rows={visible}
            rowKey={(r) => r.id}
            onRowClick={(r) => setDetailId(r.id)}
            needsAction={(r) => r.status === "pending"}
            getSearchText={(r) =>
              `${r.employee.name ?? ""} ${r.leave_type.name} ${r.reason} ${r.status}`
            }
            searchPlaceholder="Search employee, type, reason…"
            toolbar={
              <StatusFilter options={REQUEST_STATUS_OPTIONS} value={status} onChange={setStatus} />
            }
            empty="No leave requests yet."
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

      <HalfDaySection />

      <EarlyOutSection />

      <OvertimeSection />

      <RestDaySection />
    </div>
  );
}

// ---- Rest-day work requests (filed by pressing Time in on a non-workday) ----

/**
 * `expired` is in the filter and nowhere else: a pending request goes stale 24h after the punch
 * it would stamp, and HR needs to be able to find the ones that lapsed — a request that silently
 * vanishes from every view is indistinguishable from one that was never filed.
 */
const REST_DAY_STATUS_OPTIONS: StatusFilterOption[] = [
  { value: "all", label: "All" },
  { value: "pending", label: "Pending" },
  { value: "approved", label: "Approved" },
  { value: "rejected", label: "Rejected" },
  { value: "expired", label: "Expired" },
];

function RestDaySection() {
  const { data: requests, isLoading } = useRestDayRequests();
  const approveMut = useApproveRestDayRequest();
  const rejectMut = useRejectRestDayRequest();
  const reopenMut = useReopenRestDayRequest();
  const [review, setReview] = useState<{ mode: "approve" | "reject"; r: RestDayRequest } | null>(null);
  const [status, setStatus] = useState("all");
  const visible = filterByStatus(requests ?? [], status);
  const [detailId, setDetailId] = useState<number | null>(null);
  const detail = (requests ?? []).find((r) => r.id === detailId) ?? null;

  async function onConfirm(reason: string) {
    if (!review) return;
    const { mode, r } = review;
    try {
      if (mode === "approve") {
        await approveMut.mutateAsync(r.id);
        toast.success(
          `Approved ${r.employee.name}'s rest-day work`,
          `Timed in at ${formatTimeManila(r.requested_at)}, when they asked.`,
        );
      } else {
        await rejectMut.mutateAsync({ id: r.id, reason });
        toast.error(`Declined ${r.employee.name}'s rest-day work`);
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

  function restDayActions(r: RestDayRequest) {
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
        subject={`${r.employee.name}'s rest-day work`}
        consequence={
          r.status === "approved"
            ? "The attendance this approval created is removed with it. Refused once the shift has been clocked out."
            : undefined
        }
        onReopen={(reason) => reopenMut.mutateAsync({ id: r.id, reason })}
      />
    );
  }

  const columns: Column<RestDayRequest>[] = [
    {
      header: "Employee",
      cell: (r) => <EmployeeCell employee={r.employee} />,
    },
    { header: "Rest day", cell: (r) => formatDate(r.work_date) },
    {
      header: "Asked at",
      cell: (r) => (
        <span className="whitespace-nowrap font-medium text-foreground">
          {formatTimeManila(r.requested_at)}
        </span>
      ),
    },
    { header: "Pay", cell: () => <span className="text-success">Paid · rest-day premium</span> },
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
      cell: (r) => <RowActions>{restDayActions(r)}</RowActions>,
    },
  ];

  return (
    <div className="mt-10">
      <h2 className="text-h3 font-bold text-foreground">Rest-Day Work Requests</h2>
      <p className="mt-1 text-caption text-muted-foreground">
        Filed when someone presses Time in on a day their schedule calls a rest day — there is no
        form to fill in. Approving times them in at the moment they asked, not now, and the day
        earns the rest-day premium. Requests lapse 24 hours after that moment.
      </p>
      <div className="mt-4">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading rest-day requests…</p>
        ) : (
          <DataTable
            columns={columns}
            rows={visible}
            rowKey={(r) => r.id}
            onRowClick={(r) => setDetailId(r.id)}
            needsAction={(r) => r.status === "pending"}
            getSearchText={(r) => `${r.employee.name ?? ""} ${r.reason ?? ""} ${r.status}`}
            searchPlaceholder="Search employee, reason…"
            toolbar={
              <StatusFilter options={REST_DAY_STATUS_OPTIONS} value={status} onChange={setStatus} />
            }
            empty="No rest-day work requests yet. They appear here when someone times in on a rest day."
          />
        )}
      </div>

      <ReviewDialog
        open={!!review}
        onOpenChange={(o) => !o && setReview(null)}
        mode={review?.mode ?? "approve"}
        subject={
          review
            ? `${review.r.employee.name}'s rest-day work (${formatDate(review.r.work_date)}, asked at ${formatTimeManila(review.r.requested_at)})`
            : ""
        }
        onConfirm={onConfirm}
      />

      {detail && (
        <RequestDetailDialog
          open
          onOpenChange={(o) => !o && setDetailId(null)}
          title="Rest-Day Work"
          status={detail.status}
          employee={detail.employee}
          submitted={detail.created_at}
          fields={[
            { label: "Rest day", value: formatDate(detail.work_date) },
            { label: "Times in at", value: formatTimeManila(detail.requested_at) },
            { label: "Pay", value: <span className="text-success">Paid · rest-day premium</span> },
          ]}
          note={{ label: "Reason", text: detail.reason ?? "—" }}
          decision={{
            by: detail.reviewed_by,
            at: detail.reviewed_at,
            note: detail.review_note,
          }}
          actions={restDayActions(detail)}
        />
      )}
    </div>
  );
}

// ---- Half-day requests (separate from leave, always unpaid) ----

function HalfDaySection() {
  const { data: requests, isLoading } = useHalfDayRequests();
  const approveMut = useApproveHalfDayRequest();
  const rejectMut = useRejectHalfDayRequest();
  const reopenMut = useReopenHalfDayRequest();
  const [review, setReview] = useState<{ mode: "approve" | "reject"; r: HalfDayRequest } | null>(null);
  const [status, setStatus] = useState("all");
  const visible = filterByStatus(requests ?? [], status);
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
    <div className="mt-10">
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
            needsAction={(r) => r.status === "pending"}
            getSearchText={(r) =>
              `${r.employee.name ?? ""} ${r.period} ${r.reason} ${r.status}`
            }
            searchPlaceholder="Search employee, reason…"
            toolbar={
              <StatusFilter options={REQUEST_STATUS_OPTIONS} value={status} onChange={setStatus} />
            }
            empty="No half-day requests yet."
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

function EarlyOutSection() {
  const { data: requests, isLoading } = useEarlyOutRequests();
  const approveMut = useApproveEarlyOutRequest();
  const rejectMut = useRejectEarlyOutRequest();
  const reopenMut = useReopenEarlyOutRequest();
  const [review, setReview] = useState<{ mode: "approve" | "reject"; r: EarlyOutRequest } | null>(null);
  const [status, setStatus] = useState("all");
  const visible = filterByStatus(requests ?? [], status);
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
    <div className="mt-10">
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
            needsAction={(r) => r.status === "pending"}
            getSearchText={(r) =>
              `${r.employee.name ?? ""} ${r.early_out_time} ${r.reason} ${r.status}`
            }
            searchPlaceholder="Search employee, reason…"
            toolbar={
              <StatusFilter options={REQUEST_STATUS_OPTIONS} value={status} onChange={setStatus} />
            }
            empty="No early-out requests yet."
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

function OvertimeSection() {
  const { data: requests, isLoading } = useOvertimeRequests();
  const approveMut = useApproveOvertimeRequest();
  const rejectMut = useRejectOvertimeRequest();
  const cancelMut = useCancelOvertimeRequest();
  const reopenMut = useReopenOvertimeRequest();
  const [review, setReview] = useState<{
    mode: "approve" | "reject" | "cancel";
    r: OvertimeRequest;
  } | null>(null);
  const [status, setStatus] = useState("all");
  const visible = filterByStatus(requests ?? [], status);
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
          // Slice 6 — an approved request is no longer frozen: HR can undo overtime
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
    { header: "Reason", cell: (r) => <span className="text-muted-foreground">{r.reason}</span> },
    { header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
    {
      header: "Action",
      align: "right",
      cell: (r) => <RowActions>{overtimeActions(r)}</RowActions>,
    },
  ];

  return (
    <div className="mt-10">
      {/* FIN-039 — overtime the auto-pay cap held back, shown ABOVE the request table because the
          table below is where it gets resolved: approving a request for that date releases it. */}
      <AutoOvertimeReview />

      <h2 className="mt-10 text-h3 font-bold text-foreground">Overtime Requests</h2>
      <p className="mt-1 text-caption text-muted-foreground">
        Hours worked beyond the schedule. Approving ADDS pay for that period, at the company&apos;s
        overtime rate for that day (regular / rest day / special day / regular holiday).
      </p>
      <div className="mt-4">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading overtime requests…</p>
        ) : (
          <DataTable
            columns={columns}
            rows={visible}
            rowKey={(r) => r.id}
            onRowClick={(r) => setDetailId(r.id)}
            needsAction={(r) => r.status === "pending"}
            getSearchText={(r) => `${r.employee.name ?? ""} ${r.hours} ${r.reason} ${r.status}`}
            searchPlaceholder="Search employee, reason…"
            toolbar={
              <StatusFilter options={REQUEST_STATUS_OPTIONS} value={status} onChange={setStatus} />
            }
            empty="No overtime requests yet."
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

      {/* Cancelling an APPROVED request needs its own reason prompt (Slice 6). The shared
          ReviewDialog only knows approve/reject and lives in core/, so reuse the HR one. */}
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
