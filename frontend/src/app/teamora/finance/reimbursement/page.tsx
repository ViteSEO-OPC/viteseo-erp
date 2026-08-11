"use client";

import { useMemo, useState } from "react";
import { Info, Check, X, Paperclip } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import { StatCard } from "@/core/components/stat-card";
import { MoneyStatCard } from "@/modules/hr/components/money-stat-card";
import { EmployeeCell } from "@/modules/hr/components/employee-cell";
import { StatusBadge } from "@/core/components/status-badge";
import { ReviewDialog } from "@/core/components/review-dialog";
import { ReopenAction } from "@/modules/hr/components/reopen-action";
import { RequestDetailDialog } from "@/modules/hr/components/request-detail-dialog";
import { DataTable, type Column } from "@/core/components/data-table";
import { formatPeso, formatDate } from "@/core/lib/format";
import {
  useReimbursements,
  useApproveReimbursement,
  useRejectReimbursement,
  useMarkReimbursementPaid,
  useReopenReimbursement,
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
  const reopenMut = useReopenReimbursement();

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

  // Which summary-card amounts are masked (privacy show/hide toggle).
  const [hiddenAmounts, setHiddenAmounts] = useState<Record<string, boolean>>({});
  const toggleAmount = (key: string) =>
    setHiddenAmounts((h) => ({ ...h, [key]: !h[key] }));

  const [review, setReview] = useState<{ mode: "approve" | "reject"; c: Reimbursement } | null>(null);
  // Kept as an id and read back from the live list so deciding inside the modal
  // moves the status shown there too.
  const [detailId, setDetailId] = useState<number | null>(null);
  const detail = (claims ?? []).find((c) => c.id === detailId) ?? null;

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

  /** The same decisions on the row and in the detail modal. */
  function claimActions(c: Reimbursement) {
    if (c.status === "pending") {
      return (
        <>
          <Button size="sm" variant="success" disabled={busyId === c.id}
            onClick={() => setReview({ mode: "approve", c })}>
            <Check /> Approve
          </Button>
          <Button size="sm" variant="destructive" disabled={busyId === c.id}
            onClick={() => setReview({ mode: "reject", c })}>
            <X /> Reject
          </Button>
        </>
      );
    }

    const undo = (
      <ReopenAction
        subject={`${c.employee.name}'s ${categoryLabel(c.category)} claim`}
        label={c.status === "paid" ? "Undo payment" : "Reopen"}
        title={c.status === "paid" ? "Reverse this payment?" : undefined}
        confirmLabel={c.status === "paid" ? "Reverse payment" : undefined}
        successMessage={c.status === "paid" ? "Payment reversed" : "Claim reopened"}
        description={
          c.status === "paid"
            ? `This ${formatPeso(c.amount)} claim goes back to approved and unpaid — it is owed again.`
            : undefined
        }
        onReopen={(reason) => reopenMut.mutateAsync({ id: c.id, reason })}
      />
    );

    // `scheduled` (Slice 12) = folded into a payslip that hasn't gone out yet. It pays
    // itself when that payslip is disbursed, but HR can still settle it by hand. No undo
    // here: payroll is already carrying it — reverse the payslip instead.
    if (c.status === "approved" || c.status === "scheduled") {
      return (
        <>
          <Button size="sm" variant="outline" disabled={busyId === c.id}
            onClick={() => act(paidMut.mutateAsync(c.id), "Marked as paid")}
            title={c.status === "scheduled"
              ? "Already included in a payslip — use this only if paid separately"
              : undefined}>
            Mark paid
          </Button>
          {c.status === "approved" ? undo : null}
        </>
      );
    }
    return undo;
  }

  const columns: Column<Reimbursement>[] = [
    {
      header: "Employee",
      cell: (c) => <EmployeeCell employee={c.employee} />,
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
              onClick={(e) => e.stopPropagation()}
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
      cell: (c) => (
        <div className="flex justify-end gap-1.5" onClick={(e) => e.stopPropagation()}>
          {claimActions(c)}
        </div>
      ),
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
        {/* The two cards carrying peso figures get a show/hide; the other two are counts. */}
        <MoneyStatCard
          label="Pending Claims"
          value={String(stats.pending)}
          subNote={`${formatPeso(stats.pendingSum)} awaiting`}
          // The headline is a claim count, not an amount — only the peso figure below it hides.
          maskValue={false}
          hidden={!!hiddenAmounts.pending}
          onToggle={() => toggleAmount("pending")}
        />
        <StatCard
          label="Approved Unpaid"
          value={stats.approved + stats.scheduled}
          subNote={
            stats.scheduled > 0
              ? `${stats.scheduled} already in a payslip, awaiting disbursement`
              : "Queued for payroll"
          }
        />
        <MoneyStatCard
          label="Paid"
          value={formatPeso(stats.paidSum)}
          subNote={`${stats.paidCount} claim${stats.paidCount === 1 ? "" : "s"} processed`}
          hidden={!!hiddenAmounts.paid}
          onToggle={() => toggleAmount("paid")}
        />
        <StatCard label="Avg Processing" value="2.1 days" subNote="Last 30 days (mock)" />
      </div>

      <div className="mt-6">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading claims…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">Could not load claims.</p>
        ) : (
          <DataTable columns={columns} rows={claims ?? []} rowKey={(c) => c.id}
            onRowClick={(c) => setDetailId(c.id)}
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

      {detail && (
        <RequestDetailDialog
          open
          onOpenChange={(o) => !o && setDetailId(null)}
          title={`${categoryLabel(detail.category)} claim`}
          status={detail.status}
          employee={detail.employee}
          submitted={detail.created_at}
          fields={[
            {
              label: "Amount",
              value: (
                <span className="text-base font-bold">{formatPeso(detail.amount)}</span>
              ),
            },
            { label: "Category", value: categoryLabel(detail.category) },
            { label: "Spent on", value: formatDate(detail.expense_date) },
            {
              label: "Payment",
              value:
                detail.status === "paid"
                  ? "Settled"
                  : detail.status === "scheduled"
                    ? "In a payslip, awaiting disbursement"
                    : detail.status === "approved"
                      ? "Owed — queued for the next payroll run"
                      : undefined,
            },
          ]}
          note={{ label: "What it was for", text: detail.description }}
          decision={{
            by: detail.reviewed_by,
            at: detail.reviewed_at,
            note: detail.review_note,
          }}
          actions={claimActions(detail)}
        >
          {detail.receipt_urls.length > 0 && (
            <div className="space-y-1.5">
              <p className="text-label font-semibold uppercase tracking-wide text-brand">
                Receipts ({detail.receipt_urls.length})
              </p>
              <ul className="space-y-1">
                {detail.receipt_urls.map((url, i) => (
                  <li key={url}>
                    <a
                      href={url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="flex items-center gap-2 rounded-md border border-border px-2.5 py-1.5 text-sm text-foreground transition-colors hover:bg-muted"
                    >
                      <Paperclip className="size-4 shrink-0 text-muted-foreground" />
                      Receipt {i + 1}
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </RequestDetailDialog>
      )}
    </div>
  );
}
