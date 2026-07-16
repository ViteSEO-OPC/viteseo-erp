"use client";

import { useState } from "react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
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
    <div className="p-8">
      <PageHeader title="Payroll" subtitle="Payslip copy requests and payroll inquiries." />

      {/* Payslip requests */}
      <section className="mt-6">
        <h2 className="mb-3 text-h3 font-bold text-foreground">Payslip requests</h2>
        <PayslipQueue />
        <p className="mt-3 text-sm text-muted-foreground">
          All payslip requests must be approved by Miss Chris and must include a valid reason.
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

  async function onConfirm(reason: string) {
    if (!review) return;
    try {
      if (review.mode === "approve") {
        await approveMut.mutateAsync(review.row.id);
        toast.success("Payslip request approved");
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
        <DataTable columns={columns} rows={rows ?? []} rowKey={(r) => r.id} needsAction={(r) => r.status === "pending"} empty="No payslip requests." />
      )}
      <ReviewDialog
        open={!!review}
        onOpenChange={(o) => !o && setReview(null)}
        mode={review?.mode ?? "approve"}
        subject={review ? `${review.row.employee.name}'s payslip request` : ""}
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
        return (
          <div className="flex justify-end gap-2">
            {r.status === "pending" && (
              <Button
                size="sm"
                className="bg-info text-white hover:bg-info/90"
                disabled={progressMut.isPending}
                onClick={() => onStart(r)}
              >
                Start
              </Button>
            )}
            <Button size="sm" variant="success" onClick={() => setResolving(r)}>
              Mark Done
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
        Employees submit payroll inquiries via the &ldquo;Request Inquiry&rdquo; button on their
        payslip view. HR is notified in-app and via Telegram. HR follows up via Telegram and resolves
        inquiries here.
      </p>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : isError ? (
        <p className="text-sm text-destructive">Could not load inquiries.</p>
      ) : (
        <DataTable columns={columns} rows={rows ?? []} rowKey={(r) => r.id} needsAction={(r) => r.status !== "done"} empty="No payroll inquiries." />
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

  async function confirm() {
    if (!inquiry) return;
    try {
      await resolveMut.mutateAsync({ id: inquiry.id, note: note.trim() || undefined });
      toast.success("Inquiry marked done");
      onOpenChange(false);
    } catch {
      toast.error("Could not resolve the inquiry.");
    }
  }

  return (
    <Dialog open={!!inquiry} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Resolve inquiry</DialogTitle>
          <DialogDescription>
            Mark {inquiry?.employee.name}&apos;s inquiry as done. Add a resolution note (optional).
          </DialogDescription>
        </DialogHeader>
        <textarea
          rows={3}
          value={note}
          onChange={(e) => setNote(e.target.value)}
          placeholder="How was it resolved? (optional)"
          className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring"
        />
        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Cancel</Button>
          </DialogClose>
          <Button variant="success" disabled={resolveMut.isPending} onClick={confirm}>
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
        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Close</Button>
          </DialogClose>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
