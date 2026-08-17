"use client";

import { useState } from "react";
import { X, Paperclip } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { PageHeader } from "@/modules/hr/components/page-header";
import { EmployeeCell } from "@/modules/hr/components/employee-cell";
import { DataTable, type Column } from "@/core/components/data-table";
import {
  StatusFilter,
  filterByStatus,
  type StatusFilterOption,
} from "@/core/components/status-filter";
import { StatusBadge } from "@/core/components/status-badge";
import { ReviewDialog } from "@/core/components/review-dialog";
import { ReopenAction } from "@/modules/hr/components/reopen-action";
import { RequestDetailDialog } from "@/modules/hr/components/request-detail-dialog";
import { formatDate, formatDateTime } from "@/core/lib/format";
import {
  usePayslipRequests,
  useApprovePayslipRequest,
  useRejectPayslipRequest,
  useReopenPayslipRequest,
  usePayrollInquiries,
  useProgressPayrollInquiry,
  useResolvePayrollInquiry,
  useReopenPayrollInquiry,
} from "@/modules/hr/hooks/use-requests";
import type { PayslipRequest, PayrollInquiry } from "@/modules/hr/types/request";

// Red-outline "Reject" button (matches the Figma) over the neutral outline variant.
const REJECT_BTN = "border-danger text-danger hover:bg-danger-subtle hover:text-danger";

// Figma label for a declined payslip request (our status is "rejected").
const PAYSLIP_STATUS_LABEL: Record<string, string> = { rejected: "Declined" };

const PAYSLIP_STATUS_OPTIONS: StatusFilterOption[] = [
  { value: "all", label: "All" },
  { value: "pending", label: "Pending" },
  { value: "approved", label: "Approved" },
  { value: "rejected", label: "Declined" },
];

const INQUIRY_STATUS_OPTIONS: StatusFilterOption[] = [
  { value: "all", label: "All" },
  { value: "pending", label: "Pending" },
  { value: "in_progress", label: "In progress" },
  { value: "done", label: "Done" },
];

/** Elapsed time between two timestamps → "1 day 2 hrs" / "1 hr 45 min" / "12 min". */
function turnaround(from: string, to: string | null): string {
  if (!to) return "—";
  const mins = Math.round((new Date(to).getTime() - new Date(from).getTime()) / 60000);
  if (mins <= 0) return "—";
  const days = Math.floor(mins / 1440);
  const hrs = Math.floor((mins % 1440) / 60);
  const rem = mins % 60;
  if (days > 0) return `${days} day${days > 1 ? "s" : ""} ${hrs} hr${hrs !== 1 ? "s" : ""}`;
  if (hrs > 0) return `${hrs} hr ${rem} min`;
  return `${rem} min`;
}

/** Keeps a press on a decision button from also counting as a press on the row. */
function RowActions({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-end gap-2" onClick={(e) => e.stopPropagation()}>
      {children}
    </div>
  );
}

/** Employee-supplied or HR-supplied files, linked out. */
function AttachmentList({
  label,
  files,
}: {
  label: string;
  files: { url: string; name: string }[];
}) {
  return (
    <div className="space-y-1.5">
      <p className="text-label font-semibold uppercase tracking-wide text-brand">{label}</p>
      <ul className="space-y-1">
        {files.map((f, i) => (
          <li key={i}>
            <a
              href={f.url}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-2 rounded-md border border-border px-2.5 py-1.5 text-sm text-foreground transition-colors hover:bg-muted"
            >
              <Paperclip className="size-4 shrink-0 text-muted-foreground" />
              <span className="truncate">{f.name}</span>
            </a>
          </li>
        ))}
      </ul>
    </div>
  );
}

export default function PayrollRequestsPage() {
  return (
    <div className="p-4 sm:p-8">
      <PageHeader title="Payroll" subtitle="Signed hard-copy requests and payroll inquiries." />

      {/* Payslip requests */}
      <section className="mt-6">
        <h2 className="mb-3 text-h3 font-bold text-foreground">Signed hard copy requests</h2>
        <PayslipQueue />
      </section>

      {/* Payroll inquiries */}
      <InquiryQueue />
    </div>
  );
}

// ---- Payslip requests ----

function PayslipQueue() {
  const { data: rows, isLoading, isError } = usePayslipRequests();
  const approveMut = useApprovePayslipRequest();
  const rejectMut = useRejectPayslipRequest();
  const reopenMut = useReopenPayslipRequest();
  const [review, setReview] = useState<{ mode: "approve" | "reject"; row: PayslipRequest } | null>(null);
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
        await approveMut.mutateAsync(review.row.id);
        toast.success("Hard-copy request approved");
      } else {
        await rejectMut.mutateAsync({ id: review.row.id, reason });
        toast.error("Request declined", "The requester has been notified.");
      }
    } catch {
      toast.error("Something went wrong. Please try again.");
    }
  }

  /** The same decisions on the row and in the detail modal. */
  function payslipActions(r: PayslipRequest) {
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
        subject={`${r.employee.name}'s hard-copy request`}
        onReopen={(reason) => reopenMut.mutateAsync({ id: r.id, reason })}
      />
    );
  }

  const columns: Column<PayslipRequest>[] = [
    { header: "Employee", cell: (r) => <EmployeeCell employee={r.employee} /> },
    { header: "Payslip Period", cell: (r) => r.payslip_cycle ?? "—" },
    { header: "Reason", cell: (r) => r.reason },
    { header: "Date Requested", cell: (r) => <span className="text-muted-foreground">{formatDate(r.created_at)}</span> },
    { header: "Status", cell: (r) => <StatusBadge status={r.status} label={PAYSLIP_STATUS_LABEL[r.status]} /> },
    {
      header: "Action",
      align: "right",
      cell: (r) => <RowActions>{payslipActions(r)}</RowActions>,
    },
  ];

  return (
    <>
      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : isError ? (
        <p className="text-sm text-destructive">Could not load payslip requests.</p>
      ) : (
        <DataTable
          columns={columns}
          rows={visible}
          rowKey={(r) => r.id}
          onRowClick={(r) => setDetailId(r.id)}
          needsAction={(r) => r.status === "pending"}
          getSearchText={(r) =>
            `${r.employee.name ?? ""} ${r.payslip_cycle ?? ""} ${r.reason} ${r.status}`
          }
          searchPlaceholder="Search employee, period, reason…"
          toolbar={<StatusFilter options={PAYSLIP_STATUS_OPTIONS} value={status} onChange={setStatus} />}
          empty="No hard-copy requests."
        />
      )}
      <ReviewDialog
        open={!!review}
        onOpenChange={(o) => !o && setReview(null)}
        mode={review?.mode ?? "approve"}
        subject={review ? `${review.row.employee.name}'s signed hard-copy request` : ""}
        onConfirm={onConfirm}
      />

      {detail && (
        <RequestDetailDialog
          open
          onOpenChange={(o) => !o && setDetailId(null)}
          title="Signed hard copy"
          status={detail.status}
          statusLabel={PAYSLIP_STATUS_LABEL[detail.status]}
          employee={detail.employee}
          submitted={detail.created_at}
          fields={[
            { label: "Payslip period", value: detail.payslip_cycle ?? "Not specific" },
          ]}
          note={{ label: "Why they need it", text: detail.reason }}
          decision={{
            by: detail.reviewed_by,
            at: detail.reviewed_at,
            note: detail.review_note,
          }}
          actions={payslipActions(detail)}
        />
      )}
    </>
  );
}

// ---- Payroll inquiries ----

function InquiryQueue() {
  const { data: rows, isLoading, isError } = usePayrollInquiries();
  const progressMut = useProgressPayrollInquiry();
  const reopenMut = useReopenPayrollInquiry();
  const [resolving, setResolving] = useState<PayrollInquiry | null>(null);
  const [status, setStatus] = useState("all");
  const visible = filterByStatus(rows ?? [], status);
  // Kept as an id and read back from the live list: resolving from inside the modal
  // has to move it from "in progress" to "done" without closing it.
  const [detailId, setDetailId] = useState<number | null>(null);
  const detail = (rows ?? []).find((r) => r.id === detailId) ?? null;

  const hasHighPriority = (rows ?? []).some(
    (r) => r.priority === "high" && r.status !== "done",
  );

  async function onStart(r: PayrollInquiry) {
    try {
      await progressMut.mutateAsync(r.id);
    } catch {
      toast.error("Could not update the inquiry.");
    }
  }

  /**
   * The queue is a one-action-at-a-time ladder — Pending → Start, In progress → Mark Done —
   * with the undo for each. Shared by the row and the detail modal.
   */
  function inquiryActions(r: PayrollInquiry) {
    if (r.status === "done") {
      return (
        <ReopenAction
          subject={`${r.employee.name}'s inquiry`}
          consequence="The resolution note and any proof attached to it are cleared."
          onReopen={(reason) => reopenMut.mutateAsync({ id: r.id, reason })}
        />
      );
    }
    if (r.status === "in_progress") {
      return (
        <>
          <Button size="sm" variant="success" onClick={() => setResolving(r)}>
            Mark Done
          </Button>
          <ReopenAction
            label="Undo start"
            title="Send this back to pending?"
            confirmLabel="Send back"
            successMessage="Back to pending"
            subject={`${r.employee.name}'s inquiry`}
            description={`${r.employee.name}'s inquiry goes back to pending, as if it hadn't been picked up.`}
            onReopen={(reason) => reopenMut.mutateAsync({ id: r.id, reason })}
          />
        </>
      );
    }
    return (
      <Button
        size="sm"
        className="bg-info text-white hover:bg-info/90"
        disabled={progressMut.isPending}
        onClick={() => onStart(r)}
      >
        Start
      </Button>
    );
  }

  const columns: Column<PayrollInquiry>[] = [
    { header: "Employee", cell: (r) => <EmployeeCell employee={r.employee} /> },
    {
      header: "Inquiry Topic",
      cell: (r) => (
        <span className="block max-w-[220px] truncate">{r.subject ?? r.topic ?? r.message}</span>
      ),
    },
    {
      header: "Pay period",
      cell: (r) =>
        r.payslip_cycle ? (
          <span className="whitespace-nowrap text-foreground">{r.payslip_cycle}</span>
        ) : (
          <span className="text-muted-foreground">Not specific</span>
        ),
    },
    { header: "Requested", cell: (r) => <span className="text-muted-foreground">{formatDateTime(r.created_at)}</span> },
    { header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
    { header: "Resolved", cell: (r) => <span className="text-muted-foreground">{r.resolved_at ? formatDateTime(r.resolved_at) : "—"}</span> },
    { header: "Turnaround", cell: (r) => <span className="text-muted-foreground">{turnaround(r.created_at, r.resolved_at)}</span> },
    {
      header: "Action",
      align: "right",
      cell: (r) => <RowActions>{inquiryActions(r)}</RowActions>,
    },
  ];

  return (
    <section className="mt-10">
      <div className="flex items-center gap-3">
        <h2 className="text-h3 font-bold text-foreground">Payroll inquiry queue</h2>
      </div>
      <p className="mt-1 mb-4 text-sm text-muted-foreground">
        Employees raise inquiries from a specific payslip — the pay period shows in the table.
        HR is notified in-app and via Telegram, follows up there, and resolves inquiries here.
      </p>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : isError ? (
        <p className="text-sm text-destructive">Could not load inquiries.</p>
      ) : (
        <DataTable
          columns={columns}
          rows={visible}
          rowKey={(r) => r.id}
          onRowClick={(r) => setDetailId(r.id)}
          needsAction={(r) => r.status !== "done"}
          getSearchText={(r) =>
            `${r.employee.name ?? ""} ${r.subject ?? r.topic ?? r.message ?? ""} ${r.status}`
          }
          searchPlaceholder="Search employee, topic…"
          toolbar={<StatusFilter options={INQUIRY_STATUS_OPTIONS} value={status} onChange={setStatus} />}
          empty="No payroll inquiries."
        />
      )}

      <ResolveDialog
        key={resolving?.id ?? "none"}
        inquiry={resolving}
        onOpenChange={(o) => !o && setResolving(null)}
      />

      {detail && (
        <RequestDetailDialog
          open
          onOpenChange={(o) => !o && setDetailId(null)}
          title={detail.subject || detail.topic || "Payroll inquiry"}
          status={detail.status}
          employee={detail.employee}
          submitted={detail.created_at}
          fields={[
            { label: "Pay period", value: detail.payslip_cycle ?? "Not specific" },
            { label: "Topic", value: detail.topic },
            {
              label: "Priority",
              value:
                detail.priority === "high" ? (
                  <span className="font-semibold text-danger">High</span>
                ) : (
                  <span className="capitalize">{detail.priority}</span>
                ),
            },
            { label: "Turnaround", value: turnaround(detail.created_at, detail.resolved_at) },
          ]}
          note={{ label: "Inquiry", text: detail.message }}
          decision={{
            label: "Resolution",
            by: detail.resolved_by,
            at: detail.resolved_at,
            note: detail.resolution_note,
          }}
          actions={inquiryActions(detail)}
        >
          {detail.attachment_url && (
            <AttachmentList
              label="Attached by employee"
              files={[{ url: detail.attachment_url, name: "Attachment" }]}
            />
          )}
          {detail.resolution_attachments.length > 0 && (
            <AttachmentList
              label={`Proof attached (${detail.resolution_attachments.length})`}
              files={detail.resolution_attachments}
            />
          )}
        </RequestDetailDialog>
      )}
    </section>
  );
}

function ResolveDialog({
  inquiry,
  onOpenChange,
}: {
  inquiry: PayrollInquiry | null;
  onOpenChange: (open: boolean) => void;
}) {
  const resolveMut = useResolvePayrollInquiry();
  const [note, setNote] = useState("");
  const [files, setFiles] = useState<File[]>([]);

  function addFiles(list: FileList | null) {
    if (list) setFiles((prev) => [...prev, ...Array.from(list)]);
  }
  function removeFile(index: number) {
    setFiles((prev) => prev.filter((_, i) => i !== index));
  }

  const canSubmit = note.trim().length > 0 && !resolveMut.isPending;

  async function confirm() {
    if (!inquiry || !note.trim()) return;
    try {
      await resolveMut.mutateAsync({ id: inquiry.id, note: note.trim(), files });
      toast.success("Inquiry marked done");
      onOpenChange(false);
    } catch {
      toast.error("Could not resolve the inquiry. Check the note and files, then retry.");
    }
  }

  return (
    <Dialog open={!!inquiry} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Resolve inquiry</DialogTitle>
          <DialogDescription>
            Explain how {inquiry?.employee.name}&apos;s inquiry was resolved. Attach proof if you have it.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-1.5">
          <Label htmlFor="res-note">
            How was it resolved? <span className="text-destructive">*</span>
          </Label>
          <textarea
            id="res-note"
            rows={3}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder="Describe the resolution…"
            className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="res-files">Proof (optional) — images, PDF, or Word</Label>
          <input
            id="res-files"
            type="file"
            multiple
            accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx"
            onChange={(e) => { addFiles(e.target.files); e.target.value = ""; }}
            className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-foreground hover:file:bg-muted/70"
          />
          {files.length > 0 && (
            <ul className="space-y-1 pt-1">
              {files.map((f, i) => (
                <li
                  key={`${f.name}-${i}`}
                  className="flex items-center justify-between gap-2 rounded-md border border-border px-2.5 py-1.5 text-sm"
                >
                  <span className="flex min-w-0 items-center gap-2">
                    <Paperclip className="size-4 shrink-0 text-muted-foreground" />
                    <span className="truncate">{f.name}</span>
                  </span>
                  <button
                    type="button"
                    onClick={() => removeFile(i)}
                    className="shrink-0 text-muted-foreground transition-colors hover:text-destructive"
                    aria-label={`Remove ${f.name}`}
                  >
                    <X className="size-4" />
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>

        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Cancel</Button>
          </DialogClose>
          <Button variant="success" disabled={!canSubmit} onClick={confirm}>
            {resolveMut.isPending ? "Saving…" : "Mark done"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

