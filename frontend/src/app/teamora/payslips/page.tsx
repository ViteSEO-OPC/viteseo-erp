"use client";

import { useState } from "react";
import { Download, FileText, Lock, MessageCircleQuestion, Plus } from "lucide-react";
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
import { PageHeader } from "@/core/components/page-header";
import { StatusBadge } from "@/core/components/status-badge";
import { formatPeso, formatDate } from "@/core/lib/format";
import { useMyPayslips, usePayslip } from "@/modules/hr/hooks/use-payroll";
import { downloadMyPayslipPdf } from "@/modules/hr/api/payroll";
import {
  useCreatePayslipRequest,
  useMyPayrollInquiries,
} from "@/modules/hr/hooks/use-requests";
import { PayrollInquiryDialog } from "@/modules/hr/components/payroll-inquiry-dialog";
import type { PayslipSummary } from "@/modules/hr/types/payroll";

function cycleLabel(start: string, end: string) {
  return `${formatDate(start)} – ${formatDate(end)}`;
}

export default function PayslipsPage() {
  const { data: payslips, isLoading } = useMyPayslips();
  const list = payslips ?? [];

  // Only APPROVED payslips are viewable; default the selection to the newest one.
  const unlocked = list.filter((p) => p.unlocked);
  const [chosenId, setChosenId] = useState<number | undefined>();
  const selectedId = chosenId ?? unlocked[0]?.id;
  const selected = list.find((p) => p.id === selectedId);
  // Never fetch the gated detail for a locked payslip (the API 403s it).
  const { data: detail } = usePayslip(selected?.unlocked ? selectedId : undefined);

  const [copyId, setCopyId] = useState<number | null>(null);
  const [inquiryOpen, setInquiryOpen] = useState(false);
  const [pdfBusy, setPdfBusy] = useState(false);

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

  return (
    <div className="p-8">
      <PageHeader
        title="Payslips"
        subtitle="Request a payslip; once HR approves it you can view and download it here."
        actions={
          <Button onClick={() => setInquiryOpen(true)}>
            <Plus />
            Request Inquiry
          </Button>
        }
      />

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[340px_1fr]">
        {/* History — periods only, no amounts until unlocked */}
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
                  onRequest={() => setCopyId(p.id)}
                />
              ))}
            </div>
          )}
        </div>

        {/* Detail — only for an unlocked (approved) payslip */}
        {selected?.unlocked && detail ? (
          <div className="print-area rounded-card border border-border bg-card p-6">
            <div className="flex items-start justify-between">
              <div>
                <h2 className="text-h3 font-bold text-foreground">
                  Payslip — {cycleLabel(detail.payslip.cycle_start, detail.payslip.cycle_end)}
                </h2>
                <p className="text-caption text-muted-foreground">
                  {detail.payslip.employee.name} · Viteseo Inc.
                </p>
              </div>
              <Button
                className="print-hide"
                variant="outline"
                size="sm"
                disabled={pdfBusy}
                onClick={() => onDownloadPdf(detail.payslip.id)}
              >
                <Download />
                {pdfBusy ? "Downloading…" : "Download PDF"}
              </Button>
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
            <div className="mt-5 grid grid-cols-3 gap-4 rounded-lg border border-border p-4">
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
          <LockedPanel
            hasAny={list.length > 0}
            hasUnlocked={unlocked.length > 0}
            selected={selected}
            onRequest={selected ? () => setCopyId(selected.id) : undefined}
          />
        )}
      </div>

      {/* My payroll inquiries + HR replies (raised via Request Inquiry above) */}
      {(inquiries ?? []).length > 0 && (
        <div className="mt-6 rounded-card border border-border bg-card p-6">
          <h2 className="mb-3 text-h3 font-bold text-foreground">My Inquiries</h2>
          <div className="divide-y divide-border">
            {(inquiries ?? []).map((q) => (
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
        </div>
      )}

      <PayslipCopyDialog
        payslipId={copyId ?? undefined}
        onOpenChange={(o) => !o && setCopyId(null)}
      />
      <PayrollInquiryDialog
        open={inquiryOpen}
        onOpenChange={setInquiryOpen}
        periods={list}
        defaultPayslipId={selected?.unlocked ? selected.id : undefined}
      />
    </div>
  );
}

function PayslipRow({
  payslip,
  selected,
  onView,
  onRequest,
}: {
  payslip: PayslipSummary;
  selected: boolean;
  onView: () => void;
  onRequest: () => void;
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
        {!payslip.unlocked && <Lock className="size-3.5 text-muted-foreground" />}
        <p className="text-sm font-medium text-foreground">{label}</p>
      </div>

      {payslip.unlocked ? (
        <StatusBadge status="approved" label="Approved" />
      ) : payslip.request_status === "pending" ? (
        <span className="text-caption font-medium text-warning">Pending</span>
      ) : (
        <Button
          size="xs"
          variant="outline"
          onClick={(e) => {
            e.stopPropagation();
            onRequest();
          }}
        >
          {payslip.request_status === "rejected" ? "Request again" : "Request"}
        </Button>
      )}
    </Wrapper>
  );
}

function LockedPanel({
  hasAny,
  hasUnlocked,
  selected,
  onRequest,
}: {
  hasAny: boolean;
  hasUnlocked: boolean;
  selected?: PayslipSummary;
  onRequest?: () => void;
}) {
  if (!hasAny) {
    return (
      <div className="rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
        You have no payslips yet.
      </div>
    );
  }

  const canRequest =
    selected && !selected.unlocked && selected.request_status !== "pending" && onRequest;

  return (
    <div className="flex flex-col items-center justify-center gap-3 rounded-card border border-dashed border-border bg-card py-16 text-center">
      <Lock className="size-8 text-muted-foreground" />
      <p className="text-sm font-medium text-foreground">
        {hasUnlocked ? "Select an approved payslip to view it." : "Your payslips are locked."}
      </p>
      <p className="max-w-sm text-caption text-muted-foreground">
        Payslips stay private until you request one and HR approves it. Use the{" "}
        <span className="font-medium">Request</span> button next to a period.
      </p>
      {canRequest && (
        <Button variant="outline" size="sm" onClick={onRequest}>
          <FileText />
          Request this payslip
        </Button>
      )}
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
      toast.success("Payslip request submitted", "HR will review it.");
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
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Request a payslip copy</DialogTitle>
          <DialogDescription>
            Tell HR why you need this payslip (required). They&apos;ll approve it before
            you can view it.
          </DialogDescription>
        </DialogHeader>
        <textarea
          rows={3}
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder="e.g. for a bank loan application"
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
