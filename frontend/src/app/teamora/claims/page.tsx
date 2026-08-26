"use client";

import { useState } from "react";
import { Paperclip } from "lucide-react";
import { PageHeader } from "@/modules/hr/components/page-header";
import { StatusBadge } from "@/core/components/status-badge";
import { RequestDetailDialog } from "@/modules/hr/components/request-detail-dialog";
import { DataTable, type Column } from "@/core/components/data-table";
import type { StatusFilterOption } from "@/core/components/status-filter";
import { useServerTable } from "@/modules/hr/hooks/use-server-table";
import { formatPeso, formatDate } from "@/core/lib/format";
import { ClaimForm } from "@/modules/hr/components/claim-form";
import { Button } from "@/components/ui/button";
import { notify as toast } from "@/core/lib/notify";
import { CancelLeaveDialog } from "@/modules/hr/components/cancel-leave-dialog";
import {
  useReimbursements,
  useCancelReimbursement,
} from "@/modules/hr/hooks/use-reimbursements";
import {
  categoryLabel,
  type Reimbursement,
} from "@/modules/hr/types/reimbursement";

// A short line under the description reflecting where the claim is in its lifecycle.
// Rejected claims get nothing here: the status pill already says so, and the reason is one
// tap away in the detail modal.
function statusNote(status: string): string | null {
  switch (status) {
    case "approved":
      return "Queued for payroll";
    case "scheduled":
      return "In your next payslip";
    case "paid":
      return "Paid in your payslip";
    case "cancelled":
      return "You withdrew this claim";
    default:
      return null;
  }
}

/**
 * The claim statuses, curated rather than derived. Server mode cannot read them off the rows —
 * those are one page, so a status absent from it would have no pill at all.
 */
const CLAIM_STATUS_OPTIONS: StatusFilterOption[] = [
  { value: "all", label: "All" },
  { value: "pending", label: "Pending" },
  { value: "approved", label: "Approved" },
  { value: "scheduled", label: "Scheduled" },
  { value: "paid", label: "Paid" },
  { value: "rejected", label: "Rejected" },
  { value: "cancelled", label: "Cancelled" },
];

export default function ClaimsPage() {
  // Server-paged. This is the employee's own claims queue, and it grows for as long as they
  // work here — the browser used to receive every claim they had ever filed to show ten.
  const table = useServerTable(10);
  const { data, isLoading, isError, isFetching } = useReimbursements(table.params);
  // By id, then read back from the live list, so the modal follows an HR decision
  // that lands while it is open.
  const [detailId, setDetailId] = useState<number | null>(null);
  // The claim being withdrawn. Separate from `detailId` so the confirm sits above the detail.
  const [cancelTarget, setCancelTarget] = useState<Reimbursement | null>(null);
  const cancelMut = useCancelReimbursement();

  const all = data?.items ?? [];
  const detail = all.find((c) => c.id === detailId) ?? null;

  const columns: Column<Reimbursement>[] = [
    { header: "Category", cell: (c) => categoryLabel(c.category) },
    {
      header: "Description",
      cell: (c) => {
        const note = statusNote(c.status);
        return (
          <div>
            <p className="font-medium text-foreground">{c.description}</p>
            {note && <p className="text-caption text-muted-foreground">{note}</p>}
          </div>
        );
      },
    },
    { header: "Amount", cell: (c) => formatPeso(c.amount) },
    { header: "Submitted", cell: (c) => formatDate(c.created_at) },
    {
      header: "Status",
      align: "right",
      cell: (c) => <StatusBadge status={c.status} />,
    },
  ];

  return (
    <div className="p-4 sm:p-8">
      <PageHeader title="Claims" subtitle="File and track your expense claims." />

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[2fr_3fr]">
        <ClaimForm />

        <div className="rounded-card border border-border bg-card p-6">
          <h2 className="mb-4 text-h3 font-bold text-foreground">My Claims</h2>
          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading claims…</p>
          ) : isError ? (
            <p className="text-sm text-destructive">Could not load claims.</p>
          ) : (
            <>
              <DataTable
                columns={columns}
                rows={all}
                rowKey={(c) => c.id}
                pageSize={table.pageSize}
                onRowClick={(c) => setDetailId(c.id)}
                empty="No claims yet. File your first one."
                searchPlaceholder="Search category or description…"
                server={table.serverProps(data?.total ?? 0, isFetching, CLAIM_STATUS_OPTIONS)}
              />
            </>
          )}
        </div>
      </div>

      {detail && (
        <RequestDetailDialog
          open
          onOpenChange={(o) => !o && setDetailId(null)}
          title={`${categoryLabel(detail.category)} claim`}
          status={detail.status}
          submitted={detail.created_at}
          fields={[
            {
              label: "Amount",
              value: <span className="text-base font-bold">{formatPeso(detail.amount)}</span>,
            },
            { label: "Category", value: categoryLabel(detail.category) },
            { label: "Spent on", value: formatDate(detail.expense_date) },
            {
              label: "Payment",
              value:
                detail.status === "paid"
                  ? "Paid in your payslip"
                  : detail.status === "scheduled"
                    ? "In your next payslip"
                    : detail.status === "approved"
                      ? "Queued for payroll"
                      : undefined,
            },
          ]}
          note={{ label: "What it was for", text: detail.description }}
          actions={
            // Only while HR has yet to decide it — after that the way out is their decision.
            detail.status === "pending" ? (
              <Button
                variant="outline"
                className="text-danger hover:text-danger"
                onClick={() => setCancelTarget(detail)}
              >
                Cancel this claim
              </Button>
            ) : undefined
          }
          decision={{
            label: detail.status === "rejected" ? "Why it was declined" : "HR's decision",
            by: detail.reviewed_by,
            at: detail.reviewed_at,
            note: detail.review_note,
          }}
          cancellation={{
            at: detail.cancelled_at,
            by: detail.cancelled_by,
            reason: detail.cancel_reason,
          }}
        >
          {detail.receipt_urls.length > 0 && (
            <div className="space-y-1.5">
              <p className="text-label font-semibold uppercase tracking-wide text-brand">
                Your receipts ({detail.receipt_urls.length})
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

      <CancelLeaveDialog
        open={!!cancelTarget}
        onOpenChange={(o) => !o && setCancelTarget(null)}
        title="Cancel Claim"
        subject={cancelTarget ? `your ${categoryLabel(cancelTarget.category)} claim` : undefined}
        onConfirm={async (reason) => {
          if (!cancelTarget) return;
          try {
            await cancelMut.mutateAsync({ id: cancelTarget.id, reason });
            toast.success("Claim cancelled");
            setCancelTarget(null);
            setDetailId(null);
          } catch {
            toast.error("Could not cancel this claim.");
            throw new Error("cancel failed");
          }
        }}
      />
    </div>
  );
}
