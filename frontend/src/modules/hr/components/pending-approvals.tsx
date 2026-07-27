"use client";

import { useState } from "react";
import Link from "next/link";
import { ChevronRight } from "lucide-react";
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

type Kind = "leave" | "claim" | "change" | "payslip" | "document" | "inquiry" | "concern";

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
  claim: "Expense",
  change: "Profile",
  payslip: "Payslip",
  document: "Document",
  inquiry: "Payroll",
  concern: "Concern",
};

// Chip colours mapped to the semantic status tokens (no raw hexes).
const KIND_TONE: Record<Kind, string> = {
  leave: "bg-success-subtle text-success",
  claim: "bg-warning-subtle text-warning",
  change: "bg-brand-subtle text-brand-foreground",
  payslip: "bg-severity-orange-subtle text-severity-orange",
  document: "bg-info-subtle text-info",
  inquiry: "bg-severity-orange-subtle text-severity-orange",
  concern: "bg-danger-subtle text-danger",
};

// Only these kinds expose a clean one-click approve / reject. Everything else is
// navigate-only (fulfilment, meetings, inquiry resolution happen on their page).
const REVIEWABLE = new Set<Kind>(["leave", "claim", "change", "payslip"]);

export function PendingApprovals() {
  const { data: leaves } = useLeaveRequests();
  const { data: claims } = useReimbursements();
  const { data: changes } = useChangeRequests();
  const { data: payslips } = usePayslipRequests();
  const { data: documents } = useDocumentRequests();
  const { data: inquiries } = usePayrollInquiries();
  const { data: concerns } = useConcerns();

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

  // Stacked on phones: at 320px the Approve/Reject pair eats ~160px of a ~240px
  // row, truncating the name and age away to nothing. Below `sm` the buttons
  // drop onto their own line so the request stays readable.
  const rowClass =
    "flex flex-col items-stretch gap-2 rounded-lg border border-border px-3.5 py-3 transition-colors hover:bg-muted/40 sm:flex-row sm:items-center sm:justify-between sm:gap-3";

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
    <div className="rounded-card border border-border bg-card p-4 sm:p-6">
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
                <div className="flex shrink-0 gap-2 [&>button]:flex-1 sm:[&>button]:flex-none">
                  <Button
                    size="sm"
                    variant="success"
                    onClick={() => setReview({ mode: "approve", item })}
                  >
                    Approve
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    className="border-danger text-danger hover:bg-danger/10 hover:text-danger"
                    onClick={() => setReview({ mode: "reject", item })}
                  >
                    Reject
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
