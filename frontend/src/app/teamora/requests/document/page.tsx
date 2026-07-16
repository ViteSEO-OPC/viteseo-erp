"use client";

import { useState } from "react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/core/components/page-header";
import { DataTable, PrimaryCell, type Column } from "@/core/components/data-table";
import { StatusBadge } from "@/core/components/status-badge";
import { ReviewDialog } from "@/core/components/review-dialog";
import { formatDate } from "@/core/lib/format";
import {
  useDocumentRequests,
  useFulfillDocumentRequest,
  useRejectDocumentRequest,
  useChangeRequests,
  useApproveChangeRequest,
  useRejectChangeRequest,
} from "@/modules/hr/hooks/use-requests";
import {
  documentTypeLabel,
  type DocumentRequest,
  type ChangeRequest,
} from "@/modules/hr/types/request";

// Figma labels for the document-request statuses.
const DOC_STATUS_LABEL: Record<string, string> = {
  pending: "Pending",
  in_progress: "Processing",
  fulfilled: "Completed",
  rejected: "Rejected",
};

// Red-outline "Reject" button (matches the Figma) over the neutral outline variant.
const REJECT_BTN = "border-danger text-danger hover:bg-danger-subtle hover:text-danger";

export default function RequestsPage() {
  return (
    <div className="p-8">
      <PageHeader
        title="Document"
        subtitle="Employees request documents from HR through the portal - payroll, leave, and attendance records. Requests are tracked with status and fulfilled from the employee's digital 201 file."
      />

      <div className="mt-6">
        <DocumentQueue />
      </div>

      <div className="mt-10">
        <h2 className="text-h3 font-bold text-foreground">Change Requests</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Profile change requests are submitted by employees and approved by Miss Chris.
        </p>
        <div className="mt-4">
          <ChangeQueue />
        </div>
      </div>
    </div>
  );
}

// ---- Document requests queue ----

function DocumentQueue() {
  const { data: rows, isLoading, isError } = useDocumentRequests();
  const fulfillMut = useFulfillDocumentRequest();
  const rejectMut = useRejectDocumentRequest();
  const [review, setReview] = useState<{ mode: "approve" | "reject"; row: DocumentRequest } | null>(null);

  async function onConfirm(reason: string) {
    if (!review) return;
    try {
      if (review.mode === "approve") {
        await fulfillMut.mutateAsync({ id: review.row.id, note: reason || undefined });
        toast.success("Marked completed");
      } else {
        await rejectMut.mutateAsync({ id: review.row.id, reason });
        toast.error("Request rejected", "The requester has been notified.");
      }
    } catch {
      toast.error("Something went wrong. Please try again.");
    }
  }

  const columns: Column<DocumentRequest>[] = [
    { header: "Employee", cell: (r) => <PrimaryCell title={r.employee.name ?? "?"} /> },
    {
      header: "Document Type",
      cell: (r) => (r.type === "other" && r.other_detail ? r.other_detail : documentTypeLabel(r.type)),
    },
    { header: "Date Requested", cell: (r) => <span className="text-muted-foreground">{formatDate(r.created_at)}</span> },
    { header: "Status", cell: (r) => <StatusBadge status={r.status} label={DOC_STATUS_LABEL[r.status]} /> },
    {
      header: "Action",
      align: "right",
      cell: (r) => {
        if (r.status === "fulfilled") {
          return (
            <Button
              size="sm"
              variant="success"
              onClick={() =>
                r.fulfilled_url
                  ? window.open(r.fulfilled_url, "_blank", "noopener")
                  : toast.error("No file attached to this request.")
              }
            >
              View
            </Button>
          );
        }
        if (r.status === "pending") {
          return (
            <div className="flex justify-end gap-2">
              <Button size="sm" variant="success" onClick={() => setReview({ mode: "approve", row: r })}>
                Approve
              </Button>
              <Button size="sm" variant="outline" className={REJECT_BTN} onClick={() => setReview({ mode: "reject", row: r })}>
                Reject
              </Button>
            </div>
          );
        }
        return <span className="text-muted-foreground">—</span>;
      },
    },
  ];

  return (
    <>
      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading requests…</p>
      ) : isError ? (
        <p className="text-sm text-destructive">Could not load requests.</p>
      ) : (
        <DataTable columns={columns} rows={rows ?? []} rowKey={(r) => r.id} needsAction={(r) => r.status === "pending"} empty="No document requests." />
      )}
      <ReviewDialog
        open={!!review}
        onOpenChange={(o) => !o && setReview(null)}
        mode={review?.mode ?? "approve"}
        subject={review ? `the ${documentTypeLabel(review.row.type)} request from ${review.row.employee.name}` : ""}
        onConfirm={onConfirm}
      />
    </>
  );
}

// ---- Profile change requests queue ----

function ChangeQueue() {
  const { data: rows, isLoading, isError } = useChangeRequests();
  const approveMut = useApproveChangeRequest();
  const rejectMut = useRejectChangeRequest();
  const [review, setReview] = useState<{ mode: "approve" | "reject"; row: ChangeRequest } | null>(null);

  async function onConfirm(reason: string) {
    if (!review) return;
    try {
      if (review.mode === "approve") {
        await approveMut.mutateAsync(review.row.id);
        toast.success("Change approved", "The employee record was updated.");
      } else {
        await rejectMut.mutateAsync({ id: review.row.id, reason });
        toast.error("Change rejected", "The requester has been notified.");
      }
    } catch {
      toast.error("Something went wrong. Please try again.");
    }
  }

  const columns: Column<ChangeRequest>[] = [
    { header: "Field/ID", cell: (r) => <PrimaryCell title={r.field_label} /> },
    { header: "Old Value", cell: (r) => <span className="text-muted-foreground">{r.old_value || "—"}</span> },
    { header: "New Value", cell: (r) => <span className="text-foreground">{r.new_value || "—"}</span> },
    { header: "Date Submitted", cell: (r) => <span className="text-muted-foreground">{formatDate(r.created_at)}</span> },
    { header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
    {
      header: "Action",
      align: "right",
      cell: (r) =>
        r.status === "pending" ? (
          <div className="flex justify-end gap-2">
            <Button size="sm" variant="success" onClick={() => setReview({ mode: "approve", row: r })}>
              Approve
            </Button>
            <Button size="sm" variant="outline" className={REJECT_BTN} onClick={() => setReview({ mode: "reject", row: r })}>
              Reject
            </Button>
          </div>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
  ];

  return (
    <>
      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading requests…</p>
      ) : isError ? (
        <p className="text-sm text-destructive">Could not load requests.</p>
      ) : (
        <DataTable columns={columns} rows={rows ?? []} rowKey={(r) => r.id} needsAction={(r) => r.status === "pending"} empty="No change requests." />
      )}
      <ReviewDialog
        open={!!review}
        onOpenChange={(o) => !o && setReview(null)}
        mode={review?.mode ?? "approve"}
        subject={review ? `${review.row.employee.name}'s ${review.row.field_label} change` : ""}
        onConfirm={onConfirm}
      />
    </>
  );
}
