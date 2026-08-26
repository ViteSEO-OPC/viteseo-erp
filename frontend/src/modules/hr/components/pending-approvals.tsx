"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";

import { usePendingApprovals, PENDING_APPROVALS_KEY } from "@/modules/hr/hooks/use-approvals";
import type { PendingApproval } from "@/modules/hr/api/approvals";
import Link from "next/link";
import { Check, ChevronRight, X } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { ReviewDialog } from "@/core/components/review-dialog";
import { formatShortAge } from "@/core/lib/format";
import {
  useApproveLeaveRequest,
  useRejectLeaveRequest,
} from "@/modules/hr/hooks/use-leave";
import {
  useApproveReimbursement,
  useRejectReimbursement,
} from "@/modules/hr/hooks/use-reimbursements";
import {
  useApproveChangeRequest,
  useRejectChangeRequest,
  useApprovePayslipRequest,
  useRejectPayslipRequest,
} from "@/modules/hr/hooks/use-requests";

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

/**
 * A card row, as the server sends it. `PendingApproval` from the API with `kind` narrowed to the
 * set this card knows how to badge and decide.
 */
type Item = Omit<PendingApproval, "kind"> & { kind: Kind };

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

/** What the converted queues are asked for: outstanding rows, oldest-priority first, bounded. */
export function PendingApprovals() {
  // ONE request. This card is fed by thirteen queues — leave, half-day, early-out, overtime,
  // work requests, loans, claims, field time-ins, document and profile-change requests, payslip
  // requests, payroll inquiries and concerns — and it used to ask all thirteen endpoints itself,
  // merge the answers and sort them by date here. Thirteen round trips and up to thirteen
  // hundred rows travelling to draw twenty.
  //
  // Sorting thirteen lists by date to take the oldest twenty is a database's job, and the merge
  // happens there now. The approve/reject buttons below still call each kind's own endpoint —
  // deciding a leave request and deciding a loan are different acts with different rules, and
  // that has not changed.
  const { data: queue } = usePendingApprovals();
  const qc = useQueryClient();

  const apL = useApproveLeaveRequest();
  const rjL = useRejectLeaveRequest();
  const apC = useApproveReimbursement();
  const rjC = useRejectReimbursement();
  const apCh = useApproveChangeRequest();
  const rjCh = useRejectChangeRequest();
  const apP = useApprovePayslipRequest();
  const rjP = useRejectPayslipRequest();

  const [review, setReview] = useState<{ mode: "approve" | "reject"; item: Item } | null>(null);

  // Already merged and ordered oldest-first by the server — the thing that has been waiting
  // longest is the thing most worth doing.
  // `kind` arrives as a plain string; narrowed here to the set this card can badge and decide.
  // A row whose kind this build does not know about is skipped rather than rendered blank — the
  // API and the UI can be deployed a moment apart.
  const items: Item[] = (queue?.items ?? []).filter((i): i is Item =>
    KIND_TAG[i.kind as Kind] !== undefined,
  );


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

      // The mutations invalidate their OWN queue, which this card no longer reads — it reads the
      // merged one. Without this the decided row stays on screen until something else refetches:
      // the card would keep offering Approve on a request already approved.
      qc.invalidateQueries({ queryKey: PENDING_APPROVALS_KEY });
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
            Pending {formatShortAge(item.created_at)}
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
