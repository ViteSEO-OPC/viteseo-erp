"use client";

import { useState, useMemo } from "react";
import { Download, FileSignature, MessageCircleQuestion, Clock, ChevronLeft, ChevronRight } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";
import { AxiosError } from "axios";

import { cn } from "@/lib/utils";
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
import { PageHeader } from "@/modules/hr/components/page-header";
import { StatusBadge } from "@/core/components/status-badge";
import { formatPeso, formatDate } from "@/core/lib/format";
import { useMyPayslips, usePayslip } from "@/modules/hr/hooks/use-payroll";
import { downloadMyPayslipPdf } from "@/modules/hr/api/payroll";
import {
  useCreatePayslipRequest,
  useMyPayrollInquiries,
  useMyPayslipRequests,
} from "@/modules/hr/hooks/use-requests";
import { PayrollInquiryDialog } from "@/modules/hr/components/payroll-inquiry-dialog";
import { RejectionReasonDialog } from "@/core/components/rejection-reason-dialog";
import type { PayslipSummary } from "@/modules/hr/types/payroll";
import type { PayslipRequest } from "@/modules/hr/types/request";

function cycleLabel(start: string, end: string) {
  return `${formatDate(start)} – ${formatDate(end)}`;
}

export default function PayslipsPage() {
  const { data: payslips, isLoading } = useMyPayslips();
  const list = payslips ?? [];

  // A payslip is viewable once its run is finalized; default to the newest viewable one.
  const viewable = list.filter((p) => p.unlocked);
  const [chosenId, setChosenId] = useState<number | undefined>();
  const selectedId = chosenId ?? viewable[0]?.id;
  const selected = list.find((p) => p.id === selectedId);
  // Only fetch figures for a viewable payslip (a still-processing run 403s).
  const { data: detail } = usePayslip(selected?.unlocked ? selectedId : undefined);

  const [copyId, setCopyId] = useState<number | null>(null);
  const [inquiryOpen, setInquiryOpen] = useState(false);
  const [pdfBusy, setPdfBusy] = useState(false);

  // A declined hard-copy request for the selected payslip, so the employee can see why.
  const { data: hardcopyReqs } = useMyPayslipRequests();
  const [declinedCopy, setDeclinedCopy] = useState<PayslipRequest | null>(null);
  const rejectedCopy = selected
    ? (hardcopyReqs ?? []).find((r) => r.payslip_id === selected.id && r.status === "rejected")
    : undefined;

  async function onDownloadPdf(id: number) {
    setPdfBusy(true);
    try {
      await downloadMyPayslipPdf(id);
    } catch {
      toast.error("Could not download the payslip PDF.");
    } finally {
      setPdfBusy(false);
    }
  }
  const { data: inquiries } = useMyPayrollInquiries();
  const [inqPage, setInqPage] = useState(1);
  const [inqSize, setInqSize] = useState(5);

  const inquiryList = inquiries ?? [];
  const inqPageSizes = [5, 10, 20, 50];
  const inqTotalPages = Math.max(1, Math.ceil(inquiryList.length / inqSize));
  const inqSafePage = Math.min(inqPage, inqTotalPages);
  const pagedInquiries = useMemo(
    () => inquiryList.slice((inqSafePage - 1) * inqSize, inqSafePage * inqSize),
    [inquiryList, inqSafePage, inqSize],
  );

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Payslips"
        subtitle="Your payslips appear here once each pay run is finalized. Open one to raise an inquiry or request a signed hard copy."
      />

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[340px_1fr]">
        {/* History — pay periods; finalized ones are viewable */}
        <div className="rounded-card border border-border bg-card p-4">
          <h2 className="mb-3 px-2 text-h3 font-bold text-foreground">History</h2>
          {isLoading ? (
            <p className="px-2 text-sm text-muted-foreground">Loading…</p>
          ) : list.length === 0 ? (
            <p className="px-2 text-sm text-muted-foreground">No payslips yet.</p>
          ) : (
            <div className="space-y-1">
              {list.map((p) => (
                <PayslipRow
                  key={p.id}
                  payslip={p}
                  selected={p.id === selectedId}
                  onView={() => setChosenId(p.id)}
                />
              ))}
            </div>
          )}
        </div>

        {/* Detail — only for an unlocked (approved) payslip */}
        {selected?.unlocked && detail ? (
          <div className="print-area rounded-card border border-border bg-card p-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <h2 className="text-h3 font-bold text-foreground">
                  Payslip — {cycleLabel(detail.payslip.cycle_start, detail.payslip.cycle_end)}
                </h2>
                <p className="text-caption text-muted-foreground">
                  {detail.payslip.employee.name} · Viteseo Inc.
                </p>
              </div>
              <div className="print-hide flex items-center gap-2">
                <HardCopyControl
                  status={selected?.hardcopy_status ?? "none"}
                  onRequest={() => setCopyId(detail.payslip.id)}
                  onViewReason={rejectedCopy ? () => setDeclinedCopy(rejectedCopy) : undefined}
                />
                <Button
                  variant="outline"
                  size="sm"
                  disabled={pdfBusy}
                  onClick={() => onDownloadPdf(detail.payslip.id)}
                >
                  <Download />
                  {pdfBusy ? "Downloading…" : "Download PDF"}
                </Button>
              </div>
            </div>

            <Section title="Earnings">
              <Row label="Base salary" value={formatPeso(detail.payslip.base_salary)} />
              {detail.payslip.allowances > 0 && (
                <Row label="Allowances" value={formatPeso(detail.payslip.allowances)} />
              )}
              {detail.payslip.holiday_premium > 0 && (
                <Row label="Holiday premium" value={formatPeso(detail.payslip.holiday_premium)} />
              )}
              {detail.payslip.reimbursements > 0 && (
                <Row label="Reimbursement" value={formatPeso(detail.payslip.reimbursements)} />
              )}
            </Section>

            <Section title="Deductions">
              <Row label="SSS" value={formatPeso(detail.payslip.sss)} negative />
              <Row label="PhilHealth" value={formatPeso(detail.payslip.philhealth)} negative />
              <Row label="Pag-IBIG" value={formatPeso(detail.payslip.pagibig)} negative />
              <Row label="Withholding tax" value={formatPeso(detail.payslip.withholding_tax)} negative />
            </Section>

            <div className="mt-5 flex items-center justify-between border-t-2 border-brand pt-4">
              <span className="text-lg font-bold text-foreground">Net pay</span>
              <span className="text-h2 font-bold text-brand">
                {formatPeso(detail.payslip.net_pay)}
              </span>
            </div>

            {/* YTD */}
            <div className="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-4 rounded-lg border border-border p-4">
              <Ytd label="YTD Gross" value={formatPeso(detail.ytd.gross)} />
              <Ytd label="YTD Deductions" value={formatPeso(detail.ytd.deductions)} />
              <Ytd label="YTD Net" value={formatPeso(detail.ytd.net)} />
            </div>

            {/* 13th-month accrual (basic pay earned this year ÷ 12) */}
            <div className="mt-3 flex items-center justify-between rounded-lg border border-brand/30 bg-brand-subtle px-4 py-3">
              <div>
                <p className="text-caption font-semibold text-foreground">13th-Month Pay (accrued)</p>
                <p className="text-caption text-muted-foreground">
                  Basic pay earned this year ÷ 12 · finalizes at year-end
                </p>
              </div>
              <p className="text-lg font-bold text-brand">
                {formatPeso(detail.ytd.thirteenth_month)}
              </p>
            </div>

            {/* Signatures (prints with the payslip) */}
            <div className="mt-8 flex items-end justify-between gap-8">
              <div className="flex-1">
                <div className="border-t border-foreground pt-1 text-sm font-medium text-foreground">
                  {detail.payslip.processed_by ?? "—"}
                </div>
                <p className="text-caption text-muted-foreground">Processed by (HR)</p>
              </div>
              <div className="flex-1">
                <div className="mt-6 border-t border-foreground pt-1 text-sm">&nbsp;</div>
                <p className="text-caption text-muted-foreground">Employee signature</p>
              </div>
            </div>

            <div className="print-hide mt-6 flex justify-end">
              <Button variant="ghost" size="sm" onClick={() => setInquiryOpen(true)}>
                <MessageCircleQuestion />
                Raise an inquiry about this payslip
              </Button>
            </div>
          </div>
        ) : (
          <EmptyPanel
            hasAny={list.length > 0}
            hasViewable={viewable.length > 0}
            selectedProcessing={!!selected && !selected.unlocked}
          />
        )}
      </div>

      {/* My payroll inquiries + HR replies (raised via Request Inquiry above) */}
      {inquiryList.length > 0 && (
        <div className="mt-6 rounded-card border border-border bg-card p-6">
          <h2 className="mb-3 text-h3 font-bold text-foreground">My Inquiries</h2>
          <div className="divide-y divide-border">
            {pagedInquiries.map((q) => (
              <div key={q.id} className="py-3">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-sm font-semibold text-foreground">
                      {q.subject ?? "Payroll inquiry"}
                    </p>
                    <p className="text-caption text-muted-foreground">
                      {q.topic ? `${q.topic} · ` : ""}
                      {q.payslip_cycle ?? "Not specific"}
                    </p>
                  </div>
                  <StatusBadge status={q.status} />
                </div>
                {q.status === "done" && q.resolution_note && (
                  <p className="mt-2 rounded-lg bg-muted px-3 py-2 text-caption text-foreground">
                    <span className="font-medium">HR:</span> {q.resolution_note}
                  </p>
                )}
              </div>
            ))}
          </div>
          {inquiryList.length > inqSize && (
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3 -mx-6 -mb-6">
              <div className="flex items-center gap-2 text-caption text-muted-foreground">
                <span>Rows per page</span>
                <select
                  value={inqSize}
                  onChange={(e) => { setInqSize(Number(e.target.value)); setInqPage(1); }}
                  className="h-7 rounded-md border border-input bg-card px-1.5 text-caption text-foreground outline-none focus-visible:border-ring"
                >
                  {inqPageSizes.map((n) => (
                    <option key={n} value={n}>{n}</option>
                  ))}
                </select>
                <span className="hidden sm:inline">
                  · Showing {(inqSafePage - 1) * inqSize + 1}–{Math.min(inqSafePage * inqSize, inquiryList.length)} of {inquiryList.length}
                </span>
              </div>

              <div className="flex items-center gap-1">
                <button
                  type="button"
                  disabled={inqSafePage <= 1}
                  onClick={() => setInqPage((p) => Math.max(1, p - 1))}
                  className="inline-flex size-7 items-center justify-center rounded-md border border-input bg-card text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                  aria-label="Previous page"
                >
                  <ChevronLeft className="size-4" />
                </button>
                <span className="px-2 text-caption text-foreground">
                  Page {inqSafePage} of {inqTotalPages}
                </span>
                <button
                  type="button"
                  disabled={inqSafePage >= inqTotalPages}
                  onClick={() => setInqPage((p) => Math.min(inqTotalPages, p + 1))}
                  className="inline-flex size-7 items-center justify-center rounded-md border border-input bg-card text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                  aria-label="Next page"
                >
                  <ChevronRight className="size-4" />
                </button>
              </div>
            </div>
          )}
        </div>
      )}

      <PayslipCopyDialog
        payslipId={copyId ?? undefined}
        onOpenChange={(o) => !o && setCopyId(null)}
      />
      <PayrollInquiryDialog
        open={inquiryOpen}
        onOpenChange={setInquiryOpen}
        payslip={selected?.unlocked ? selected : undefined}
      />
      <RejectionReasonDialog
        open={!!declinedCopy}
        onOpenChange={(o) => !o && setDeclinedCopy(null)}
        title="Hard-copy request declined"
        reason={declinedCopy?.review_note ?? null}
      />
    </div>
  );
}

function PayslipRow({
  payslip,
  selected,
  onView,
}: {
  payslip: PayslipSummary;
  selected: boolean;
  onView: () => void;
}) {
  const label = cycleLabel(payslip.cycle_start, payslip.cycle_end);
  const Wrapper = payslip.unlocked ? "button" : "div";

  return (
    <Wrapper
      {...(payslip.unlocked ? { onClick: onView, type: "button" as const } : {})}
      className={cn(
        "flex w-full items-center justify-between rounded-lg border-l-2 px-3 py-2.5 text-left transition-colors",
        payslip.unlocked && selected
          ? "border-brand bg-brand-subtle"
          : payslip.unlocked
            ? "border-transparent hover:bg-muted"
            : "border-transparent",
      )}
    >
      <div className="flex items-center gap-2">
        {!payslip.unlocked && <Clock className="size-3.5 text-muted-foreground" />}
        <p className={cn("text-sm font-medium", payslip.unlocked ? "text-foreground" : "text-muted-foreground")}>
          {label}
        </p>
      </div>

      {payslip.unlocked ? (
        // A pending/approved signed-hard-copy request shows a small hint here.
        payslip.hardcopy_status === "pending" ? (
          <span className="text-caption font-medium text-warning">Copy pending</span>
        ) : payslip.hardcopy_status === "approved" ? (
          <span className="text-caption font-medium text-success">Copy approved</span>
        ) : (
          <StatusBadge status="approved" label="Available" />
        )
      ) : (
        <span className="text-caption font-medium text-muted-foreground">Processing</span>
      )}
    </Wrapper>
  );
}

/** Signed-hard-copy request control shown on the payslip detail. */
function HardCopyControl({
  status,
  onRequest,
  onViewReason,
}: {
  status: PayslipSummary["hardcopy_status"];
  onRequest: () => void;
  onViewReason?: () => void;
}) {
  if (status === "pending") {
    return (
      <span className="inline-flex items-center gap-1.5 rounded-md bg-warning-subtle px-2.5 py-1.5 text-caption font-medium text-warning">
        <Clock className="size-3.5" />
        Hard copy requested
      </span>
    );
  }
  if (status === "approved") {
    return (
      <span className="inline-flex items-center gap-1.5 rounded-md bg-success-subtle px-2.5 py-1.5 text-caption font-medium text-success">
        <FileSignature className="size-3.5" />
        Hard copy approved
      </span>
    );
  }
  return (
    <div className="flex items-center gap-2">
      {status === "rejected" && onViewReason && (
        <button
          type="button"
          onClick={onViewReason}
          className="text-caption font-medium text-danger hover:underline"
        >
          Tap to see why
        </button>
      )}
      <Button variant="outline" size="sm" onClick={onRequest}>
        <FileSignature />
        {status === "rejected" ? "Request hard copy again" : "Request signed hard copy"}
      </Button>
    </div>
  );
}

function EmptyPanel({
  hasAny,
  hasViewable,
  selectedProcessing,
}: {
  hasAny: boolean;
  hasViewable: boolean;
  selectedProcessing: boolean;
}) {
  if (!hasAny) {
    return (
      <div className="rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
        You have no payslips yet.
      </div>
    );
  }

  return (
    <div className="flex flex-col items-center justify-center gap-3 rounded-card border border-dashed border-border bg-card py-16 text-center">
      <Clock className="size-8 text-muted-foreground" />
      <p className="text-sm font-medium text-foreground">
        {selectedProcessing
          ? "This payslip is still being finalized."
          : hasViewable
            ? "Select a payslip to view it."
            : "Your payslips will appear here once each pay run is finalized."}
      </p>
      <p className="max-w-sm text-caption text-muted-foreground">
        Payslips become available automatically after the pay run is completed — no request needed.
      </p>
    </div>
  );
}

function PayslipCopyDialog({
  payslipId,
  onOpenChange,
}: {
  payslipId?: number;
  onOpenChange: (open: boolean) => void;
}) {
  const copyMut = useCreatePayslipRequest();
  const [text, setText] = useState("");

  async function submit() {
    if (!text.trim() || !payslipId) return;
    try {
      await copyMut.mutateAsync({ reason: text.trim(), payslip_id: payslipId });
      toast.success("Hard-copy request submitted", "HR will prepare a signed copy.");
      setText("");
      onOpenChange(false);
    } catch (err) {
      const msg =
        err instanceof AxiosError
          ? (err.response?.data?.errors?.payslip_id?.[0] ??
            err.response?.data?.message ??
            "Could not submit. Please try again.")
          : "Could not submit. Please try again.";
      toast.error(msg);
    }
  }

  return (
    <Dialog open={payslipId != null} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Request a signed hard copy</DialogTitle>
          <DialogDescription>
            You can already view and download this payslip. Use this only to request a
            printed, signed copy from HR — tell them why you need it (required).
          </DialogDescription>
        </DialogHeader>
        <textarea
          rows={3}
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder="e.g. signed copy for a bank loan application"
          className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring"
        />
        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Cancel</Button>
          </DialogClose>
          <Button disabled={copyMut.isPending || !text.trim()} onClick={submit}>
            {copyMut.isPending ? "Submitting…" : "Submit"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="mt-6">
      <p className="mb-2 text-label font-bold uppercase tracking-wide text-muted-foreground">
        {title}
      </p>
      <div className="space-y-2">{children}</div>
    </div>
  );
}

function Row({ label, value, negative }: { label: string; value: string; negative?: boolean }) {
  return (
    <div className="flex items-center justify-between text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className={cn(negative ? "text-danger" : "text-foreground")}>
        {negative ? "− " : ""}
        {value}
      </span>
    </div>
  );
}

function Ytd({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-caption text-muted-foreground">{label}</p>
      <p className="mt-0.5 text-sm font-semibold text-foreground">{value}</p>
    </div>
  );
}
