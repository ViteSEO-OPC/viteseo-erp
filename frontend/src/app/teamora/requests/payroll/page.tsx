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
import { PageHeader } from "@/core/components/page-header";
import { Avatar } from "@/core/components/avatar";
import { DataTable, type Column } from "@/core/components/data-table";
import {
  StatusFilter,
  filterByStatus,
  type StatusFilterOption,
} from "@/core/components/status-filter";
import { StatusBadge } from "@/core/components/status-badge";
import { ReviewDialog } from "@/core/components/review-dialog";
import { formatDate, formatDateTime } from "@/core/lib/format";
import {
  usePayslipRequests,
  useApprovePayslipRequest,
  useRejectPayslipRequest,
  usePayrollInquiries,
  useProgressPayrollInquiry,
  useResolvePayrollInquiry,
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

function EmployeeCell({ employee }: { employee: { id: number; name: string | null } }) {
  const name = employee.name ?? "?";
  return (
    <div className="flex items-center gap-3">
      <Avatar name={name} colorSeed={employee.id} size="sm" />
      <span className="font-semibold text-foreground">{name}</span>
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
        <p className="mt-3 text-sm text-muted-foreground">
          Employees can already view and download their payslips. These are requests for a
          printed, signed copy — approve to prepare one, or decline with a reason.
        </p>
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
  const [review, setReview] = useState<{ mode: "approve" | "reject"; row: PayslipRequest } | null>(null);
  const [status, setStatus] = useState("all");
  const visible = filterByStatus(rows ?? [], status);

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

  const columns: Column<PayslipRequest>[] = [
    { header: "Employee", cell: (r) => <EmployeeCell employee={r.employee} /> },
    { header: "Payslip Period", cell: (r) => r.payslip_cycle ?? "—" },
    { header: "Reason", cell: (r) => r.reason },
    { header: "Date Requested", cell: (r) => <span className="text-muted-foreground">{formatDate(r.created_at)}</span> },
    { header: "Status", cell: (r) => <StatusBadge status={r.status} label={PAYSLIP_STATUS_LABEL[r.status]} /> },
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
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : isError ? (
        <p className="text-sm text-destructive">Could not load payslip requests.</p>
      ) : (
        <DataTable
          columns={columns}
          rows={visible}
          rowKey={(r) => r.id}
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
    </>
  );
}

// ---- Payroll inquiries ----

function InquiryQueue() {
  const { data: rows, isLoading, isError } = usePayrollInquiries();
  const progressMut = useProgressPayrollInquiry();
  const [resolving, setResolving] = useState<PayrollInquiry | null>(null);
  const [viewing, setViewing] = useState<PayrollInquiry | null>(null);
  const [status, setStatus] = useState("all");
  const visible = filterByStatus(rows ?? [], status);

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
      cell: (r) => {
        if (r.status === "done") {
          return (
            <Button size="sm" variant="link" className="h-auto p-0" onClick={() => setViewing(r)}>
              View Note
            </Button>
          );
        }
        // One action at a time: Pending → Start, then In progress → Mark Done.
        if (r.status === "in_progress") {
          return (
            <div className="flex justify-end">
              <Button size="sm" variant="success" onClick={() => setResolving(r)}>
                Mark Done
              </Button>
            </div>
          );
        }
        return (
          <div className="flex justify-end">
            <Button
              size="sm"
              className="bg-info text-white hover:bg-info/90"
              disabled={progressMut.isPending}
              onClick={() => onStart(r)}
            >
              Start
            </Button>
          </div>
        );
      },
    },
  ];

  return (
    <section className="mt-10">
      <div className="flex items-center gap-3">
        <h2 className="text-h3 font-bold text-foreground">Payroll inquiry queue</h2>
        {hasHighPriority && (
          <span className="inline-flex items-center rounded-full bg-danger px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-white">
            High priority
          </span>
        )}
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
      <NoteDialog inquiry={viewing} onOpenChange={(o) => !o && setViewing(null)} />
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
      <DialogContent>
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

function NoteDialog({
  inquiry,
  onOpenChange,
}: {
  inquiry: PayrollInquiry | null;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <Dialog open={!!inquiry} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Resolution note</DialogTitle>
          <DialogDescription>{inquiry?.employee.name}&apos;s payroll inquiry</DialogDescription>
        </DialogHeader>
        <p className="rounded-lg bg-muted px-3 py-2.5 text-sm text-foreground">
          {inquiry?.resolution_note?.trim() || "No note was added."}
        </p>
        {inquiry && inquiry.resolution_attachments.length > 0 && (
          <div className="space-y-1.5">
            <p className="text-xs font-semibold text-muted-foreground">
              Proof attached ({inquiry.resolution_attachments.length})
            </p>
            <ul className="space-y-1">
              {inquiry.resolution_attachments.map((a, i) => (
                <li key={i}>
                  <a
                    href={a.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex items-center gap-2 rounded-md border border-border px-2.5 py-1.5 text-sm text-foreground transition-colors hover:bg-muted"
                  >
                    <Paperclip className="size-4 shrink-0 text-muted-foreground" />
                    <span className="truncate">{a.name}</span>
                  </a>
                </li>
              ))}
            </ul>
          </div>
        )}
        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Close</Button>
          </DialogClose>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
