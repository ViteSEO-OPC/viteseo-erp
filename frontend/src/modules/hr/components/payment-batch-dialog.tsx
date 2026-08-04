"use client";

import { useState } from "react";
import { AxiosError } from "axios";
import { AlertTriangle, Banknote, CheckCircle2, Download, FileWarning } from "lucide-react";

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
import { useCreatePaymentBatch, usePaymentBatches } from "@/modules/hr/hooks/use-payroll";
import type { PaymentBatch, PaymentMethod, PaymentSkipped } from "@/modules/hr/types/payroll";

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

                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => download(batch)}
                  disabled={downloading === batch.id}
                >
                  <Download className="size-4" />
                  {downloading === batch.id ? "Preparing…" : "Bank file"}
                </Button>
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
