"use client";

import { useState } from "react";
import Link from "next/link";
import { Check, ChevronRight, X } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { ReviewDialog } from "@/core/components/review-dialog";
import { formatShortAge } from "@/core/lib/format";
import {
  useLeaveRequests,
  useApproveLeaveRequest,
  useRejectLeaveRequest,
} from "@/modules/hr/hooks/use-leave";
import {
  useReimbursements,
  useApproveReimbursement,
  useRejectReimbursement,
} from "@/modules/hr/hooks/use-reimbursements";
import {
  useDocumentRequests,
  useChangeRequests,
  useApproveChangeRequest,
  useRejectChangeRequest,
  usePayslipRequests,
  useApprovePayslipRequest,
  useRejectPayslipRequest,
  usePayrollInquiries,
} from "@/modules/hr/hooks/use-requests";
import { useConcerns } from "@/modules/hr/hooks/use-concerns";
import { useHalfDayRequests } from "@/modules/hr/hooks/use-half-day";
import { useEarlyOutRequests } from "@/modules/hr/hooks/use-early-out";
import { useOvertimeRequests } from "@/modules/hr/hooks/use-overtime";
import { useWorkRequests } from "@/modules/hr/hooks/use-work-request";
import { useAllLoans } from "@/modules/hr/hooks/use-employees";
import { useRemoteCheckins } from "@/modules/hr/hooks/use-remote-checkins";

type Kind =
  | "leave"
  | "half_day"
  | "early_out"
  | "overtime"
  | "work_request"
  | "loan"
  | "claim"
  | "change"
  | "payslip"
  | "document"
  | "inquiry"
  | "concern"
  | "timein";

interface Item {
  key: string;
  kind: Kind;
  id: number;
  name: string; // display name — "Anonymous" for anonymous concerns (never a real name)
  createdAt: string;
  href: string; // where clicking the row takes you to see the full details
}

// Chip label = the *type of request* (so "Expense", not "supplies").
const KIND_TAG: Record<Kind, string> = {
  leave: "Leave",
  half_day: "Half-day",
  early_out: "Early-out",
  overtime: "Overtime",
  work_request: "Work",
  loan: "Loan",
  claim: "Expense",
  change: "Profile",
  payslip: "Payslip",
  document: "Document",
  inquiry: "Payroll",
  concern: "Concern",
  timein: "Time-in",
};

// Chip colours mapped to the semantic status tokens (no raw hexes).
const KIND_TONE: Record<Kind, string> = {
  leave: "bg-success-subtle text-success",
  half_day: "bg-success-subtle text-success",
  early_out: "bg-warning-subtle text-warning",
  overtime: "bg-info-subtle text-info",
  work_request: "bg-info-subtle text-info",
  loan: "bg-warning-subtle text-warning",
  claim: "bg-warning-subtle text-warning",
  change: "bg-brand-subtle text-brand-foreground",
  payslip: "bg-severity-orange-subtle text-severity-orange",
  document: "bg-info-subtle text-info",
  inquiry: "bg-severity-orange-subtle text-severity-orange",
  concern: "bg-danger-subtle text-danger",
  timein: "bg-info-subtle text-info",
};

// Only these kinds expose a clean one-click approve / reject. Everything else is
// navigate-only (fulfilment, meetings, inquiry resolution happen on their page).
const REVIEWABLE = new Set<Kind>(["leave", "claim", "change", "payslip"]);

/* Approve/Reject collapse to icon-only squares when the card is narrow. */
const ACTION_BTN = "w-8 px-0 @[28rem]/approvals:w-auto @[28rem]/approvals:px-3";
const ACTION_LABEL = "sr-only @[28rem]/approvals:not-sr-only";

export function PendingApprovals() {
  const { data: leaves } = useLeaveRequests();
  const { data: claims } = useReimbursements();
  const { data: changes } = useChangeRequests();
  const { data: payslips } = usePayslipRequests();
  const { data: documents } = useDocumentRequests();
  const { data: inquiries } = usePayrollInquiries();
  const { data: concerns } = useConcerns();
  // The four that were reviewable from the day they shipped and appeared in no aggregate:
  // half-day, early-out and overtime (decided on the work-schedule page) and loan requests
  // (decided on the employee's record). They are navigate-only here — see REVIEWABLE.
  const { data: halfDays } = useHalfDayRequests();
  const { data: earlyOuts } = useEarlyOutRequests();
  const { data: overtimes } = useOvertimeRequests();
  // Work requests (a rest day, or a suspended day HR is tracking). Navigate-only like the
  // other three: approving one WRITES an attendance row stamped at the employee's punch, which
  // is not a decision to take from a feed row that shows neither the day nor the time it would
  // stamp.
  const { data: workRequests } = useWorkRequests();
  const { data: loans } = useAllLoans();
  // Already filtered to pending by the endpoint's default.
  const { data: checkins } = useRemoteCheckins();

  const apL = useApproveLeaveRequest();
  const rjL = useRejectLeaveRequest();
  const apC = useApproveReimbursement();
  const rjC = useRejectReimbursement();
  const apCh = useApproveChangeRequest();
  const rjCh = useRejectChangeRequest();
  const apP = useApprovePayslipRequest();
  const rjP = useRejectPayslipRequest();

  const [review, setReview] = useState<{ mode: "approve" | "reject"; item: Item } | null>(null);

  const items: Item[] = [
    ...(leaves ?? [])
      .filter((l) => l.status === "pending")
      .map((l) => ({
        key: `leave-${l.id}`,
        kind: "leave" as const,
        id: l.id,
        name: l.employee.name ?? "—",
        createdAt: l.created_at,
        href: "/teamora/requests/work-schedule",
      })),
    ...(halfDays ?? [])
      .filter((h) => h.status === "pending")
      .map((h) => ({
        key: `half_day-${h.id}`,
        kind: "half_day" as const,
        id: h.id,
        name: h.employee?.name ?? "—",
        createdAt: h.created_at,
        href: "/teamora/requests/work-schedule",
      })),
    ...(earlyOuts ?? [])
      .filter((e) => e.status === "pending")
      .map((e) => ({
        key: `early_out-${e.id}`,
        kind: "early_out" as const,
        id: e.id,
        name: e.employee?.name ?? "—",
        createdAt: e.created_at,
        href: "/teamora/requests/work-schedule",
      })),
    ...(overtimes ?? [])
      .filter((o) => o.status === "pending")
      .map((o) => ({
        key: `overtime-${o.id}`,
        kind: "overtime" as const,
        id: o.id,
        name: o.employee?.name ?? "—",
        createdAt: o.created_at,
        href: "/teamora/requests/work-schedule",
      })),
    ...(workRequests ?? [])
      .filter((r) => r.status === "pending")
      .map((r) => ({
        key: `work_request-${r.id}`,
        kind: "work_request" as const,
        id: r.id,
        name: r.employee?.name ?? "—",
        createdAt: r.created_at,
        href: "/teamora/requests/work-schedule",
      })),
    ...(loans ?? [])
      .filter((l) => l.status === "requested")
      .map((l) => ({
        key: `loan-${l.id}`,
        kind: "loan" as const,
        id: l.id,
        name: l.employee?.name ?? "—",
        // `requested_at` is when the employee asked; `start_date` is the fallback for a
        // loan HR issued directly, which has no request moment.
        createdAt: l.requested_at ?? l.start_date,
        // Decided on the record's Compensation tab, where the amounts and their consent state are.
        href: `/teamora/people/employees/${l.employee?.id ?? ""}`,
      })),
    ...(claims ?? [])
      .filter((c) => c.status === "pending")
      .map((c) => ({
        key: `claim-${c.id}`,
        kind: "claim" as const,
        id: c.id,
        name: c.employee.name ?? "—",
        createdAt: c.created_at,
        href: "/teamora/finance/reimbursement",
      })),
    ...(changes ?? [])
      .filter((c) => c.status === "pending")
      .map((c) => ({
        key: `change-${c.id}`,
        kind: "change" as const,
        id: c.id,
        name: c.employee.name ?? "—",
        createdAt: c.created_at,
        href: "/teamora/requests/document",
      })),
    ...(payslips ?? [])
      .filter((p) => p.status === "pending")
      .map((p) => ({
        key: `payslip-${p.id}`,
        kind: "payslip" as const,
        id: p.id,
        name: p.employee.name ?? "—",
        createdAt: p.created_at,
        href: "/teamora/requests/payroll",
      })),
    ...(documents ?? [])
      .filter((d) => d.status === "pending")
      .map((d) => ({
        key: `document-${d.id}`,
        kind: "document" as const,
        id: d.id,
        name: d.employee.name ?? "—",
        createdAt: d.created_at,
        href: "/teamora/requests/document",
      })),
    ...(inquiries ?? [])
      .filter((q) => q.status === "pending")
      .map((q) => ({
        key: `inquiry-${q.id}`,
        kind: "inquiry" as const,
        id: q.id,
        name: q.employee.name ?? "—",
        createdAt: q.created_at,
        href: "/teamora/requests/payroll",
      })),
    ...(checkins ?? []).map((rc) => ({
      key: `timein-${rc.id}`,
      kind: "timein" as const,
      id: rc.id,
      name: rc.employee.name ?? "—",
      createdAt: rc.submitted_at,
      href: "/teamora/attendance",
    })),
    ...(concerns ?? [])
      .filter((c) => c.status === "pending")
      .map((c) => ({
        key: `concern-${c.id}`,
        kind: "concern" as const,
        id: c.id,
        // Anonymity invariant: never surface a name for an anonymous concern.
        name: c.is_anonymous ? "Anonymous" : (c.employee?.name ?? "Employee"),
        createdAt: c.created_at,
        href: "/teamora/requests/concern",
      })),
  ].sort((a, b) => a.createdAt.localeCompare(b.createdAt)); // oldest (longest-waiting) first

  async function onConfirm(reason: string) {
    if (!review) return;
    const { mode, item } = review;
    const approve = {
      leave: () => apL.mutateAsync(item.id),
      claim: () => apC.mutateAsync(item.id),
      change: () => apCh.mutateAsync(item.id),
      payslip: () => apP.mutateAsync(item.id),
    } as const;
    const reject = {
      leave: () => rjL.mutateAsync({ id: item.id, reason }),
      claim: () => rjC.mutateAsync({ id: item.id, reason }),
      change: () => rjCh.mutateAsync({ id: item.id, reason }),
      payslip: () => rjP.mutateAsync({ id: item.id, reason }),
    } as const;
    try {
      if (mode === "approve") {
        await approve[item.kind as keyof typeof approve]();
        toast.success("Approved", "The request was approved and the requester notified.");
      } else {
        await reject[item.kind as keyof typeof reject]();
        toast.error("Declined", "The requester has been notified by your note.");
      }
    } catch {
      toast.error("Action failed.");
    }
  }

  const rowClass =
    "flex items-center justify-between gap-3 rounded-lg border border-border px-3.5 py-3 transition-colors hover:bg-muted/40";

  // Chip + name + "Pending Xh" — shared by both row shapes.
  function RowBody({ item }: { item: Item }) {
    return (
      <div className="flex min-w-0 items-center gap-3">
        <span
          className={`shrink-0 rounded-md px-2 py-1 text-caption font-bold ${KIND_TONE[item.kind]}`}
        >
          {KIND_TAG[item.kind]}
        </span>
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold text-foreground">{item.name}</p>
          <p className="truncate text-caption text-muted-foreground">
            Pending {formatShortAge(item.createdAt)}
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="@container/approvals rounded-card border border-border bg-card p-4 sm:p-6">
      <h2 className="text-h3 font-bold text-foreground">Pending Approvals</h2>

      <div className="mt-4 h-80 space-y-3 overflow-y-auto pr-1">
        {items.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">
            All caught up — nothing pending. 🎉
          </p>
        ) : (
          items.map((item) =>
            REVIEWABLE.has(item.kind) ? (
              // Reviewable: left side links to the page, buttons act in place.
              <div key={item.key} className={rowClass}>
                <Link href={item.href} className="min-w-0 flex-1">
                  <RowBody item={item} />
                </Link>
                <div className="flex shrink-0 gap-2">
                  <Button
                    size="sm"
                    variant="success"
                    className={ACTION_BTN}
                    title="Approve"
                    onClick={() => setReview({ mode: "approve", item })}
                  >
                    <Check />
                    <span className={ACTION_LABEL}>Approve</span>
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    className={`border-danger text-danger hover:bg-danger/10 hover:text-danger ${ACTION_BTN}`}
                    title="Reject"
                    onClick={() => setReview({ mode: "reject", item })}
                  >
                    <X />
                    <span className={ACTION_LABEL}>Reject</span>
                  </Button>
                </div>
              </div>
            ) : (
              // Navigate-only: the whole row opens the managing page.
              <Link key={item.key} href={item.href} className={rowClass}>
                <RowBody item={item} />
                <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
              </Link>
            )
          )
        )}
      </div>

      <ReviewDialog
        open={!!review}
        onOpenChange={(o) => !o && setReview(null)}
        mode={review?.mode ?? "approve"}
        subject={review ? `${review.item.name}'s request` : ""}
        onConfirm={onConfirm}
      />
    </div>
  );
}
