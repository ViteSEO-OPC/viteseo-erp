"use client";

import { useMemo, useState } from "react";
import { Info, Check, X, Paperclip } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import { StatCard } from "@/core/components/stat-card";
import { Avatar } from "@/core/components/avatar";
import { StatusBadge } from "@/core/components/status-badge";
import { ReviewDialog } from "@/core/components/review-dialog";
import { DataTable, type Column } from "@/core/components/data-table";
import { formatPeso, formatDate } from "@/core/lib/format";
import {
  useReimbursements,
  useApproveReimbursement,
  useRejectReimbursement,
  useMarkReimbursementPaid,
} from "@/modules/hr/hooks/use-reimbursements";
import {
  categoryLabel,
  type Reimbursement,
} from "@/modules/hr/types/reimbursement";

export default function ReimbursementPage() {
  const { data: claims, isLoading, isError } = useReimbursements();
  const approveMut = useApproveReimbursement();
  const rejectMut = useRejectReimbursement();
  const paidMut = useMarkReimbursementPaid();

  const stats = useMemo(() => {
    const list = claims ?? [];
    const sum = (s: string) =>
      list.filter((c) => c.status === s).reduce((a, c) => a + parseFloat(c.amount), 0);
    return {
      pending: list.filter((c) => c.status === "pending").length,
      pendingSum: sum("pending"),
      approved: list.filter((c) => c.status === "approved").length,
      scheduled: list.filter((c) => c.status === "scheduled").length,
      paidSum: sum("paid"),
      paidCount: list.filter((c) => c.status === "paid").length,
    };
  }, [claims]);

  const [review, setReview] = useState<{ mode: "approve" | "reject"; c: Reimbursement } | null>(null);

  const busyId =
    (approveMut.isPending && approveMut.variables) ||
    (rejectMut.isPending && rejectMut.variables?.id) ||
    (paidMut.isPending && paidMut.variables) ||
    null;

  async function act(fn: Promise<unknown>, msg: string, variant: "success" | "error" = "success") {
    try {
      await fn;
      toast[variant](msg);
    } catch {
      toast.error("Action failed.");
    }
  }

  async function onConfirm(reason: string) {
    if (!review) return;
    const { mode, c } = review;
    if (mode === "approve") {
      await act(approveMut.mutateAsync(c.id), "Claim approved");
    } else {
      await act(rejectMut.mutateAsync({ id: c.id, reason }), "Claim declined", "error");
    }
  }

  const columns: Column<Reimbursement>[] = [
    {
      header: "Employee",
      cell: (c) => (
        <div className="flex items-center gap-2.5">
          <Avatar name={c.employee.name ?? "?"} colorSeed={c.employee.id} size="sm" />
          <span className="font-semibold text-foreground">{c.employee.name}</span>
        </div>
      ),
    },
    { header: "Category", cell: (c) => categoryLabel(c.category) },
    {
      header: "Description",
      cell: (c) => (
        <span className="flex items-center gap-1.5 text-muted-foreground">
          {c.description}
          {c.receipt_urls.map((url, i) => (
            <a
              key={url}
              href={url}
              target="_blank"
              rel="noopener noreferrer"
              className="text-brand hover:opacity-80"
              title={
                c.receipt_urls.length > 1
                  ? `View receipt ${i + 1} of ${c.receipt_urls.length}`
                  : "View receipt"
              }
            >
              <Paperclip className="size-3.5" />
            </a>
          ))}
        </span>
      ),
    },
    { header: "Amount", cell: (c) => formatPeso(c.amount) },
    { header: "Submitted", cell: (c) => formatDate(c.created_at) },
    { header: "Status", cell: (c) => <StatusBadge status={c.status} /> },
    {
      header: "Action",
      align: "right",
      cell: (c) => {
        if (c.status === "pending") {
          return (
            <div className="flex justify-end gap-1.5">
              <Button size="sm" variant="success" disabled={busyId === c.id}
                onClick={() => setReview({ mode: "approve", c })}>
                <Check /> Approve
              </Button>
              <Button size="sm" variant="destructive" disabled={busyId === c.id}
                onClick={() => setReview({ mode: "reject", c })}>
                <X /> Reject
              </Button>
            </div>
          );
        }
        // `scheduled` (Slice 12) = folded into a payslip that hasn't gone out yet. It pays
        // itself when that payslip is disbursed, but HR can still settle it by hand.
        if (c.status === "approved" || c.status === "scheduled") {
          return (
            <Button size="sm" variant="outline" disabled={busyId === c.id}
              onClick={() => act(paidMut.mutateAsync(c.id), "Marked as paid")}
              title={c.status === "scheduled"
                ? "Already included in a payslip — use this only if paid separately"
                : undefined}>
              Mark paid
            </Button>
          );
        }
        return <span className="text-muted-foreground">—</span>;
      },
    },
  ];

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Reimbursement"
        subtitle="Claims arrive automatically when employees file them."
      />

      <div className="mt-6 flex items-start gap-3 rounded-card border border-brand/40 bg-brand-subtle px-4 py-3">
        <Info className="mt-0.5 size-5 shrink-0 text-brand" />
        <p className="text-sm text-foreground">
          <span className="font-semibold">Claim automation is live</span> — approving
          a claim marks it ready for the next payroll run.
        </p>
      </div>

      <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Pending Claims" value={stats.pending}
          subNote={`${formatPeso(stats.pendingSum)} awaiting`} highlight />
        <StatCard
          label="Approved Unpaid"
          value={stats.approved + stats.scheduled}
          subNote={
            stats.scheduled > 0
              ? `${stats.scheduled} already in a payslip, awaiting disbursement`
              : "Queued for payroll"
          }
        />
        <StatCard label="Paid" value={formatPeso(stats.paidSum)}
          subNote={`${stats.paidCount} claim${stats.paidCount === 1 ? "" : "s"} processed`} />
        <StatCard label="Avg Processing" value="2.1 days" subNote="Last 30 days (mock)" />
      </div>

      <div className="mt-6">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading claims…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">Could not load claims.</p>
        ) : (
          <DataTable columns={columns} rows={claims ?? []} rowKey={(c) => c.id}
            needsAction={(c) => c.status === "pending" || c.status === "approved"}
            empty="No claims filed yet." />
        )}
      </div>

      <ReviewDialog
        open={!!review}
        onOpenChange={(o) => !o && setReview(null)}
        mode={review?.mode ?? "approve"}
        subject={review ? `${review.c.employee.name}'s ${categoryLabel(review.c.category)} claim (${formatPeso(review.c.amount)})` : ""}
        onConfirm={onConfirm}
      />
    </div>
  );
}
