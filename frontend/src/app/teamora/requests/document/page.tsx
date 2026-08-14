"use client";

import { useState } from "react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import { DataTable, PrimaryCell, type Column } from "@/core/components/data-table";
import {
  StatusFilter,
  filterByStatus,
  type StatusFilterOption,
} from "@/core/components/status-filter";
import { StatusBadge } from "@/core/components/status-badge";
import { ReviewDialog } from "@/core/components/review-dialog";
import { ReopenAction } from "@/modules/hr/components/reopen-action";
import { DASH, RequestDetailDialog } from "@/modules/hr/components/request-detail-dialog";
import { formatDate } from "@/core/lib/format";
import {
  useDocumentRequests,
  useFulfillDocumentRequest,
  useRejectDocumentRequest,
  useReopenDocumentRequest,
  useChangeRequests,
  useApproveChangeRequest,
  useRejectChangeRequest,
  useReopenChangeRequest,
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

const DOC_STATUS_OPTIONS: StatusFilterOption[] = [
  { value: "all", label: "All" },
  { value: "pending", label: "Pending" },
  { value: "in_progress", label: "Processing" },
  { value: "fulfilled", label: "Completed" },
  { value: "rejected", label: "Rejected" },
];

const CHANGE_STATUS_OPTIONS: StatusFilterOption[] = [
  { value: "all", label: "All" },
  { value: "pending", label: "Pending" },
  { value: "approved", label: "Approved" },
  { value: "rejected", label: "Rejected" },
];

// Red-outline "Reject" button (matches the Figma) over the neutral outline variant.
const REJECT_BTN = "border-danger text-danger hover:bg-danger-subtle hover:text-danger";

/** Keeps a press on a decision button from also counting as a press on the row. */
function RowActions({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex justify-end gap-2" onClick={(e) => e.stopPropagation()}>
      {children}
    </div>
  );
}

export default function RequestsPage() {
  return (
    <div className="p-4 sm:p-8">
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
          Profile change requests are submitted by employees.
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
  const reopenMut = useReopenDocumentRequest();
  const [review, setReview] = useState<{ mode: "approve" | "reject"; row: DocumentRequest } | null>(null);
  const [status, setStatus] = useState("all");
  const visible = filterByStatus(rows ?? [], status);
  // Kept as an id and read back from the live list so deciding inside the modal
  // moves the status shown there too.
  const [detailId, setDetailId] = useState<number | null>(null);
  const detail = (rows ?? []).find((r) => r.id === detailId) ?? null;

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

  /** The same decisions on the row and in the detail modal. */
  function docActions(r: DocumentRequest) {
    const undo = (
      <ReopenAction
        subject={`the ${documentTypeLabel(r.type)} request from ${r.employee.name}`}
        successMessage="Request reopened"
        consequence={
          r.status === "fulfilled"
            ? r.fulfilled_url
              ? "The attached file is deleted and the employee loses access to it."
              : "The employee loses access to the fulfilled request."
            : undefined
        }
        onReopen={(reason) => reopenMut.mutateAsync({ id: r.id, reason })}
      />
    );

    if (r.status === "fulfilled") {
      return (
        <>
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
          {undo}
        </>
      );
    }
    if (r.status === "pending") {
      return (
        <>
          <Button size="sm" variant="success" onClick={() => setReview({ mode: "approve", row: r })}>
            Approve
          </Button>
          <Button size="sm" variant="outline" className={REJECT_BTN} onClick={() => setReview({ mode: "reject", row: r })}>
            Reject
          </Button>
        </>
      );
    }
    if (r.status === "rejected") return undo;
    return null;
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
      cell: (r) =>
        docActions(r) ? (
          <RowActions>{docActions(r)}</RowActions>
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
        <DataTable
          columns={columns}
          rows={visible}
          rowKey={(r) => r.id}
          onRowClick={(r) => setDetailId(r.id)}
          needsAction={(r) => r.status === "pending"}
          getSearchText={(r) =>
            `${r.employee.name ?? ""} ${r.type === "other" && r.other_detail ? r.other_detail : documentTypeLabel(r.type)} ${r.status}`
          }
          searchPlaceholder="Search employee, document…"
          toolbar={<StatusFilter options={DOC_STATUS_OPTIONS} value={status} onChange={setStatus} />}
          empty="No document requests."
        />
      )}
      <ReviewDialog
        open={!!review}
        onOpenChange={(o) => !o && setReview(null)}
        mode={review?.mode ?? "approve"}
        subject={review ? `the ${documentTypeLabel(review.row.type)} request from ${review.row.employee.name}` : ""}
        onConfirm={onConfirm}
      />

      {detail && (
        <RequestDetailDialog
          open
          onOpenChange={(o) => !o && setDetailId(null)}
          title={documentTypeLabel(detail.type)}
          status={detail.status}
          statusLabel={DOC_STATUS_LABEL[detail.status]}
          employee={detail.employee}
          submitted={detail.created_at}
          fields={[
            { label: "Document", value: documentTypeLabel(detail.type) },
            { label: "Which one", value: detail.type === "other" ? detail.other_detail || DASH : undefined },
            {
              label: "Attached file",
              value: detail.fulfilled_url ? (
                <a
                  href={detail.fulfilled_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="font-medium text-brand hover:underline"
                >
                  Open document
                </a>
              ) : detail.status === "fulfilled" ? (
                <span className="text-warning">Completed without a file</span>
              ) : undefined,
            },
          ]}
          note={{ label: "Purpose", text: detail.reason }}
          decision={{
            by: detail.reviewed_by,
            at: detail.reviewed_at,
            note: detail.review_note,
          }}
          actions={docActions(detail)}
        />
      )}
    </>
  );
}

// ---- Profile change requests queue ----

function ChangeQueue() {
  const { data: rows, isLoading, isError } = useChangeRequests();
  const approveMut = useApproveChangeRequest();
  const rejectMut = useRejectChangeRequest();
  const reopenMut = useReopenChangeRequest();
  const [review, setReview] = useState<{ mode: "approve" | "reject"; row: ChangeRequest } | null>(null);
  const [status, setStatus] = useState("all");
  const visible = filterByStatus(rows ?? [], status);
  const [detailId, setDetailId] = useState<number | null>(null);
  const detail = (rows ?? []).find((r) => r.id === detailId) ?? null;

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

  function changeActions(r: ChangeRequest) {
    if (r.status === "pending") {
      return (
        <>
          <Button size="sm" variant="success" onClick={() => setReview({ mode: "approve", row: r })}>
            Approve
          </Button>
          <Button size="sm" variant="outline" className={REJECT_BTN} onClick={() => setReview({ mode: "reject", row: r })}>
            Reject
          </Button>
        </>
      );
    }
    return (
      <ReopenAction
        subject={`${r.employee.name}'s ${r.field_label} change`}
        consequence={
          r.status === "approved"
            ? `Their ${r.field_label} goes back to ${r.old_value || "empty"} — unless HR has edited it since.`
            : undefined
        }
        successMessage={({ restored }) =>
          restored ? "Reopened and the old value restored" : "Change request reopened"
        }
        // Annotated so the result type is inferred here: an un-annotated callback
        // makes both props context-sensitive and the generic collapses to unknown.
        onReopen={(reason: string) => reopenMut.mutateAsync({ id: r.id, reason })}
      />
    );
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
      cell: (r) => <RowActions>{changeActions(r)}</RowActions>,
    },
  ];

  return (
    <>
      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading requests…</p>
      ) : isError ? (
        <p className="text-sm text-destructive">Could not load requests.</p>
      ) : (
        <DataTable
          columns={columns}
          rows={visible}
          rowKey={(r) => r.id}
          onRowClick={(r) => setDetailId(r.id)}
          needsAction={(r) => r.status === "pending"}
          getSearchText={(r) =>
            `${r.field_label} ${r.old_value ?? ""} ${r.new_value ?? ""} ${r.status}`
          }
          searchPlaceholder="Search field, value…"
          toolbar={<StatusFilter options={CHANGE_STATUS_OPTIONS} value={status} onChange={setStatus} />}
          empty="No change requests."
        />
      )}
      <ReviewDialog
        open={!!review}
        onOpenChange={(o) => !o && setReview(null)}
        mode={review?.mode ?? "approve"}
        subject={review ? `${review.row.employee.name}'s ${review.row.field_label} change` : ""}
        onConfirm={onConfirm}
      />

      {detail && (
        <RequestDetailDialog
          open
          onOpenChange={(o) => !o && setDetailId(null)}
          title={`${detail.field_label} change`}
          status={detail.status}
          employee={detail.employee}
          submitted={detail.created_at}
          fields={[
            { label: "Field", value: detail.field_label },
            { label: "Current value", value: detail.old_value || DASH },
            {
              label: "Requested value",
              value: <span className="font-medium">{detail.new_value || DASH}</span>,
            },
          ]}
          decision={{
            by: detail.reviewed_by,
            at: detail.reviewed_at,
            note: detail.review_note,
          }}
          actions={changeActions(detail)}
        />
      )}
    </>
  );
}
