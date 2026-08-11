"use client";

import { useState } from "react";
import { AxiosError } from "axios";
import { AlertTriangle, Banknote, CheckCircle2, ChevronRight, Download, FileWarning, Upload, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/core/ui/badge";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { notify as toast } from "@/core/lib/notify";
import { formatPeso } from "@/core/lib/format";
import { downloadBankFile } from "@/modules/hr/api/payroll";
import {
  useCreatePaymentBatch,
  usePaymentBatch,
  usePaymentBatches,
  useReconcilePaymentBatch,
  useSettlePayment,
} from "@/modules/hr/hooks/use-payroll";
import type {
  Payment,
  PaymentBatch,
  PaymentMethod,
  PaymentSkipped,
  PaymentStatus,
} from "@/modules/hr/types/payroll";

/** How each transfer outcome reads, and how loudly. */
const PAYMENT_TONE: Record<PaymentStatus, "success" | "danger" | "warning" | "neutral"> = {
  settled: "success",
  failed: "danger",
  returned: "warning",
  pending: "neutral",
};

/** The exclusion reasons, in the order a person would want to read them. */
const SKIP_LABELS: Record<keyof PaymentSkipped, string> = {
  missing_bank_details: "no bank details on file",
  already_batched: "already in a payment batch",
  already_disbursed: "already marked paid",
  zero_net: "net pay of zero",
};

/**
 * Pay a completed run (FIN-015).
 *
 * WHAT WAS MISSING. Payroll computed net pay correctly and then stopped tracking it. Disbursement
 * was a manual per-payslip flip with no payment record, no bank reference and no way to learn that
 * a transfer had bounced; the bank details collected on every employee were never used at all.
 *
 * WHY THE FORM ASKS SO LITTLE. Who gets paid, and how much, comes from the run — never typed. A
 * typed amount would be a second, unreconciled answer to "what does payroll owe", which is the
 * whole class of defect this work exists to close. The only real decision is how the money leaves.
 *
 * BUILDING A BATCH DOES NOT MARK ANYONE PAID. It says "we intend to pay these". Only the bank's
 * own confirmation says money moved — that is the difference between `computed`, `disbursed` and
 * `settled`, and collapsing it is exactly the overstatement FIN-052 is about.
 *
 * THE SKIPPED LIST IS NOT A FOOTNOTE. A batch that quietly omits three people looks identical to
 * one that does not, and the number finance checks is whether the batch equals the run. So both
 * the exclusions and the reconciliation are shown, and an unbalanced batch is called out rather
 * than left for someone to notice at month end.
 */
export function PaymentBatchDialog({
  open,
  onOpenChange,
  payrollRunId,
  runTotalNet,
  runLabel,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  payrollRunId: number | null;
  runTotalNet: number;
  runLabel: string;
}) {
  const [method, setMethod] = useState<PaymentMethod>("bank_transfer");
  const [notes, setNotes] = useState("");
  const [downloading, setDownloading] = useState<number | null>(null);
  const [expanded, setExpanded] = useState<number | null>(null);

  const { data: batches = [], isLoading } = usePaymentBatches(payrollRunId ?? undefined);
  const create = useCreatePaymentBatch();

  const paidSoFar = batches.reduce((sum, b) => sum + b.total_amount, 0);
  const outstanding = Math.round((runTotalNet - paidSoFar) * 100) / 100;

  async function submit() {
    if (!payrollRunId) return;

    try {
      const { batch, skipped, reconciliation } = await create.mutateAsync({
        payroll_run_id: payrollRunId,
        method,
        notes: notes.trim() || null,
      });

      const left = (Object.entries(skipped) as [keyof PaymentSkipped, number][])
        .filter(([, n]) => n > 0)
        .map(([reason, n]) => `${n} ${SKIP_LABELS[reason]}`);

      toast.success(
        `${batch.reference}: ${batch.payment_count} payment(s), ${formatPeso(batch.total_amount)}` +
          (left.length ? ` — left out: ${left.join(", ")}` : ""),
      );

      if (!reconciliation.balanced) {
        toast.warning(
          `This batch does not cover the whole run — ${reconciliation.unpaid_payslips} payslip(s) still unpaid, ` +
            `${formatPeso(Math.abs(reconciliation.difference))} short. Pay the rest by cash or check, or add the missing bank details.`,
        );
      }

      setNotes("");
    } catch (error) {
      const message =
        (error as AxiosError<{ message?: string }>)?.response?.data?.message ??
        "Could not build the payment batch.";
      toast.error(message);
    }
  }

  async function download(batch: PaymentBatch) {
    setDownloading(batch.id);
    try {
      await downloadBankFile(batch.id, batch.reference);
    } catch {
      toast.error("Could not download the bank file.");
    } finally {
      setDownloading(null);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Pay this run</DialogTitle>
          <DialogDescription>
            {runLabel} — net {formatPeso(runTotalNet)}. Amounts come from the payroll run; a batch
            records the intention to pay and produces the file for the bank. Nobody is marked as
            paid until the transfer is confirmed.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          {outstanding > 0.01 && batches.length > 0 && (
            <div className="flex items-start gap-2 rounded-lg border border-warning bg-warning-subtle px-3 py-2">
              <AlertTriangle className="mt-0.5 size-4 shrink-0" />
              <p className="text-caption">
                {formatPeso(outstanding)} of this run is still unpaid across{" "}
                {batches.length} batch(es).
              </p>
            </div>
          )}

          <div className="flex flex-wrap items-end gap-3">
            <div className="min-w-40 flex-1 space-y-1.5">
              <Label htmlFor="batch-method">How is this paid?</Label>
              <SimpleSelect
                id="batch-method"
                value={method}
                onValueChange={(v) => setMethod(v as PaymentMethod)}
                options={[
                  { value: "bank_transfer", label: "Bank transfer" },
                  { value: "cash", label: "Cash" },
                  { value: "check", label: "Check" },
                ]}
              />
            </div>

            <div className="min-w-48 flex-[2] space-y-1.5">
              <Label htmlFor="batch-notes">Notes (optional)</Label>
              <Input
                id="batch-notes"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                placeholder="e.g. second cutoff, paid early for the holiday"
                maxLength={500}
              />
            </div>

            <Button onClick={submit} disabled={create.isPending || !payrollRunId}>
              <Banknote className="size-4" />
              {create.isPending ? "Building…" : "Build batch"}
            </Button>
          </div>

          {/*
            Bank transfer is the only method that needs an account number, so it is the only one
            that can silently leave someone out. Said up front rather than discovered afterwards.
          */}
          {method === "bank_transfer" && (
            <p className="text-caption text-muted-foreground flex items-start gap-2">
              <FileWarning className="mt-0.5 size-3.5 shrink-0" />
              Employees with no bank account on file are left out of a transfer batch. Pay them by
              cash or check, or add their account numbers first.
            </p>
          )}

          <div className="space-y-2">
            <p className="text-caption text-muted-foreground font-medium">Batches for this run</p>

            {isLoading && <p className="text-caption text-muted-foreground">Loading…</p>}

            {!isLoading && batches.length === 0 && (
              <p className="text-caption text-muted-foreground">
                No batches yet — this run has not been paid.
              </p>
            )}

            {batches.map((batch) => (
              <div
                key={batch.id}
                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border px-3 py-2"
              >
                <div className="min-w-0">
                  <p className="flex items-center gap-2 text-sm font-medium">
                    {batch.reference}
                    <Badge tone={batch.exported_at ? "info" : "neutral"}>{batch.status}</Badge>
                    {batch.exported_at && (
                      <span className="text-caption text-muted-foreground inline-flex items-center gap-1">
                        <CheckCircle2 className="size-3" /> sent to bank
                      </span>
                    )}
                  </p>
                  <p className="text-caption text-muted-foreground">
                    {batch.payment_count} payment(s) · {formatPeso(batch.total_amount)} ·{" "}
                    {batch.method.replace("_", " ")}
                    {batch.notes ? ` · ${batch.notes}` : ""}
                  </p>
                </div>

                <div className="flex items-center gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => download(batch)}
                    disabled={downloading === batch.id}
                  >
                    <Download className="size-4" />
                    {downloading === batch.id ? "Preparing…" : "Bank file"}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setExpanded(expanded === batch.id ? null : batch.id)}
                  >
                    <ChevronRight
                      className={`size-4 transition-transform ${expanded === batch.id ? "rotate-90" : ""}`}
                    />
                    Transfers
                  </Button>
                </div>

                {expanded === batch.id && (
                  <div className="w-full border-t pt-3">
                    <BatchTransfers batchId={batch.id} />
                  </div>
                )}
              </div>
            ))}

            {/*
              Downloading stamps the batch as sent and it can no longer be rebuilt — once the file
              exists the company has to assume the bank has it, and regenerating from changed
              payslips is how someone gets paid twice.
            */}
            {batches.some((b) => !b.exported_at) && (
              <p className="text-caption text-muted-foreground">
                Downloading the bank file marks a batch as sent. It cannot be rebuilt after that.
              </p>
            )}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Close
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * The transfers in one batch, and what the bank did with each (FIN-015).
 *
 * THIS IS WHERE "PAID" BECOMES A FACT. A batch on its own only says the company intended to pay.
 * Marking a transfer settled is what moves the payslip to `disbursed`; marking one failed is the
 * case that was previously invisible — a bounced transfer used to leave the payslip reading paid
 * with nothing anywhere to contradict it.
 *
 * Two ways in, because companies differ: upload the bank's return file, or work down the list by
 * hand against a statement. Small teams will do the latter and it must not feel like the fallback.
 */
function BatchTransfers({ batchId }: { batchId: number }) {
  const { data, isLoading } = usePaymentBatch(batchId);
  const settle = useSettlePayment();
  const reconcile = useReconcilePaymentBatch();

  const [failing, setFailing] = useState<number | null>(null);
  const [reason, setReason] = useState("");

  async function mark(payment: Payment, status: PaymentStatus, failureReason?: string) {
    try {
      await settle.mutateAsync({
        id: payment.id,
        status,
        failure_reason: failureReason ?? null,
      });
      toast.success(
        status === "settled"
          ? `${payment.employee?.name ?? "Transfer"} confirmed as paid.`
          : `Recorded as ${status}. The payslip is no longer marked paid.`,
      );
      setFailing(null);
      setReason("");
    } catch (error) {
      const message =
        (error as AxiosError<{ message?: string }>)?.response?.data?.message ??
        "Could not record that.";
      toast.error(message);
    }
  }

  async function upload(file: File, confirm: boolean) {
    try {
      const result = await reconcile.mutateAsync({ id: batchId, file, confirm });
      const summary = `${result.settled} settled, ${result.failed} failed, ${result.unmatched.length} unmatched`;

      if (!confirm) {
        toast.info(`Preview — ${summary}. Nothing has changed yet.`, result.unmatched.join(" · "));
        return;
      }

      toast.success(`Applied — ${summary}.`);
      if (result.unmatched.length) {
        toast.warning(`${result.unmatched.length} row(s) were not applied.`, result.unmatched.join(" · "));
      }
    } catch (error) {
      const message =
        (error as AxiosError<{ message?: string }>)?.response?.data?.message ??
        "Could not read that file.";
      toast.error(message);
    }
  }

  if (isLoading) return <p className="text-caption text-muted-foreground">Loading transfers…</p>;
  if (!data) return null;

  return (
    <div className="space-y-3">
      {/*
        Preview first, apply second — deliberately two clicks. This import moves payslips into
        `disbursed`, so it should not run on a file nobody has read back.
      */}
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-caption text-muted-foreground">Bank return file:</span>
        {/* Both controls are styled buttons hiding their own input — the pair reads as one
            two-step action, which a bare browser file widget beside a button did not. */}
        <Button variant="outline" size="sm" asChild>
          <label>
            <Upload className="size-4" />
            Preview
            <input
              id={`recon-${batchId}`}
              type="file"
              accept=".csv,text/csv"
              className="hidden"
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) void upload(file, false);
                e.target.value = "";
              }}
            />
          </label>
        </Button>
        <span className="text-caption text-muted-foreground">then</span>
        <Button variant="outline" size="sm" asChild>
          <label>
            <Upload className="size-4" />
            Apply
            <input
              type="file"
              accept=".csv,text/csv"
              className="hidden"
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) void upload(file, true);
                e.target.value = "";
              }}
            />
          </label>
        </Button>
      </div>

      <div className="space-y-1">
        {data.batch.payments?.map((payment) => (
          <div key={payment.id} className="flex flex-wrap items-center justify-between gap-2 py-1">
            <div className="min-w-0">
              <p className="text-caption font-medium">
                {payment.employee?.name ?? payment.employee?.employee_no ?? `Payment ${payment.id}`}{" "}
                <Badge tone={PAYMENT_TONE[payment.status]}>{payment.status}</Badge>
              </p>
              <p className="text-caption text-muted-foreground">
                {formatPeso(payment.amount)}
                {payment.bank_account_masked ? ` · ${payment.bank_account_masked}` : ""}
                {payment.reference ? ` · ref ${payment.reference}` : ""}
                {payment.failure_reason ? ` · ${payment.failure_reason}` : ""}
              </p>
            </div>

            {payment.status === "pending" && (
              <div className="flex items-center gap-1">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => void mark(payment, "settled")}
                  disabled={settle.isPending}
                >
                  <CheckCircle2 className="size-4" />
                  Paid
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setFailing(failing === payment.id ? null : payment.id)}
                >
                  <X className="size-4" />
                  Failed
                </Button>
              </div>
            )}
          </div>
        ))}
      </div>

      {/* A failure needs a reason — somebody has to chase it, and "failed" tells them nothing. */}
      {failing !== null && (
        <div className="flex flex-wrap items-end gap-2 rounded-lg border border-warning bg-warning-subtle px-3 py-2">
          <div className="min-w-48 flex-1 space-y-1.5">
            <Label htmlFor="fail-reason">Why did it fail?</Label>
            <Input
              id="fail-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="e.g. account closed, name mismatch"
              maxLength={500}
            />
          </div>
          <Button
            size="sm"
            disabled={!reason.trim() || settle.isPending}
            onClick={() => {
              const payment = data.batch.payments?.find((p) => p.id === failing);
              if (payment) void mark(payment, "failed", reason.trim());
            }}
          >
            Record failure
          </Button>
          <Button variant="outline" size="sm" onClick={() => setFailing(null)}>
            Cancel
          </Button>
        </div>
      )}
    </div>
  );
}
