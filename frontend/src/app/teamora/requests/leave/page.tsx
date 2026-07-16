"use client";

import { useState } from "react";
import { Zap, Check, X } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/core/components/page-header";
import { Avatar } from "@/core/components/avatar";
import { StatusBadge } from "@/core/components/status-badge";
import { ReviewDialog } from "@/core/components/review-dialog";
import {
  DataTable,
  type Column,
} from "@/core/components/data-table";
import { formatDate } from "@/core/lib/format";
import {
  useLeaveRequests,
  useApproveLeaveRequest,
  useRejectLeaveRequest,
} from "@/modules/hr/hooks/use-leave";
import {
  useHalfDayRequests,
  useApproveHalfDayRequest,
  useRejectHalfDayRequest,
} from "@/modules/hr/hooks/use-half-day";
import type { LeaveRequest, HalfDayRequest } from "@/modules/hr/types/leave";

export default function TimeLeavePage() {
  const { data: requests, isLoading, isError } = useLeaveRequests();
  const approveMut = useApproveLeaveRequest();
  const rejectMut = useRejectLeaveRequest();
  const [review, setReview] = useState<{ mode: "approve" | "reject"; r: LeaveRequest } | null>(null);

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

  const busyId =
    (approveMut.isPending && approveMut.variables) ||
    (rejectMut.isPending && rejectMut.variables?.id) ||
    null;

  const columns: Column<LeaveRequest>[] = [
    {
      header: "Employee",
      cell: (r) => (
        <div className="flex items-center gap-2.5">
          <Avatar name={r.employee.name ?? "?"} colorSeed={r.employee.id} size="sm" />
          <span className="font-semibold text-foreground">{r.employee.name}</span>
        </div>
      ),
    },
    { header: "Type", cell: (r) => r.leave_type.name },
    {
      header: "Dates",
      cell: (r) =>
        r.start_date === r.end_date
          ? formatDate(r.start_date)
          : `${formatDate(r.start_date)} – ${formatDate(r.end_date)}`,
    },
    { header: "Days", cell: (r) => r.days },
    {
      header: "Reason",
      cell: (r) => <span className="text-muted-foreground">{r.reason}</span>,
    },
    { header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
    {
      header: "Action",
      align: "right",
      cell: (r) =>
        r.status === "pending" ? (
          <div className="flex justify-end gap-1.5">
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
          </div>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
  ];

  return (
    <div className="p-8">
      <PageHeader
        title="Time & Leave"
        subtitle="Review and decide on employee leave requests."
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
            rows={requests ?? []}
            rowKey={(r) => r.id}
            needsAction={(r) => r.status === "pending"}
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

      <HalfDaySection />
    </div>
  );
}

// ---- Half-day requests (separate from leave, always unpaid) ----

function HalfDaySection() {
  const { data: requests, isLoading } = useHalfDayRequests();
  const approveMut = useApproveHalfDayRequest();
  const rejectMut = useRejectHalfDayRequest();
  const [review, setReview] = useState<{ mode: "approve" | "reject"; r: HalfDayRequest } | null>(null);

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

  const columns: Column<HalfDayRequest>[] = [
    {
      header: "Employee",
      cell: (r) => (
        <div className="flex items-center gap-2.5">
          <Avatar name={r.employee.name ?? "?"} colorSeed={r.employee.id} size="sm" />
          <span className="font-semibold text-foreground">{r.employee.name}</span>
        </div>
      ),
    },
    { header: "Date", cell: (r) => formatDate(r.work_date) },
    { header: "Half", cell: (r) => r.period },
    { header: "Pay", cell: () => <span className="text-danger">Unpaid · 0.5 day</span> },
    { header: "Reason", cell: (r) => <span className="text-muted-foreground">{r.reason}</span> },
    { header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
    {
      header: "Action",
      align: "right",
      cell: (r) =>
        r.status === "pending" ? (
          <div className="flex justify-end gap-1.5">
            <Button size="sm" variant="success" disabled={busyId === r.id} onClick={() => setReview({ mode: "approve", r })}>
              <Check />
              Approve
            </Button>
            <Button size="sm" variant="destructive" disabled={busyId === r.id} onClick={() => setReview({ mode: "reject", r })}>
              <X />
              Reject
            </Button>
          </div>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
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
            rows={requests ?? []}
            rowKey={(r) => r.id}
            needsAction={(r) => r.status === "pending"}
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
    </div>
  );
}
