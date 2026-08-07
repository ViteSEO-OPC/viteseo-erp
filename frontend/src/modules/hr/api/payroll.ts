import { apiClient } from "@/core/api-client";
import type {
  NextPayday,
  PayrollRun,
  PayrollSettings,
  Payslip,
  PayslipSummary,
  PayslipYtd,
  ThirteenthMonthRow,
  ThirteenthMonthPayRow,
  AdjustmentLine,
  RetroFlag,
  DeductionArrear,
  PaymentBatch,
  PaymentMethod,
  PaymentReconciliation,
  PaymentReconcileResult,
  PaymentSkipped,
  PaymentStatus,
  Payment,
} from "@/modules/hr/types/payroll";

export async function getPayrollSettings(): Promise<PayrollSettings> {
  const res = await apiClient.get("/hr/payroll/settings");
  return res.data.data;
}

export async function updatePayrollSettings(input: {
  frequency: string;
  deduction_timing?: string;
  tax_timing?: string;
  /** Slice 10 — null clears the ceiling (allowances fully tax-free). */
  other_benefits_annual_cap?: number | null;
  /**
   * FIN-025 — the date these settings take effect. Omitted means today. Payroll resolves settings
   * as of the cycle end, so this is what stops a change reaching back into periods already run.
   */
  effective_from?: string;
  /** FIN-030 — the regional minimum daily wage. Null clears it (warns about nothing). */
  minimum_daily_wage?: number | null;
}): Promise<PayrollSettings> {
  const res = await apiClient.put("/hr/payroll/settings", input);
  return res.data.data;
}

export async function getPayrollRuns(): Promise<PayrollRun[]> {
  const res = await apiClient.get("/hr/payroll/runs");
  return res.data.data;
}

export async function getPayrollRun(id: number): Promise<PayrollRun> {
  const res = await apiClient.get(`/hr/payroll/runs/${id}`);
  return res.data.data;
}

export async function runPayroll(input: {
  frequency: string;
  cycle_start: string;
  cycle_end: string;
}): Promise<PayrollRun> {
  const res = await apiClient.post("/hr/payroll/run", input);
  return res.data.data;
}

export async function markPayslipDone(id: number): Promise<PayrollRun> {
  const res = await apiClient.post(`/hr/payslips/${id}/mark-done`);
  return res.data.data;
}

/**
 * Undo a disbursement: the payslip goes back to computed and any claims it settled
 * are owed on this run again. Refused while the run is locked.
 */
export async function reopenPayslip(id: number, reason?: string): Promise<PayrollRun> {
  const res = await apiClient.post(`/hr/payslips/${id}/reopen`, { reason });
  return res.data.data;
}

/** Seal a fully-disbursed run so it can never be recomputed (Slice 5). */
export async function lockPayrollRun(id: number): Promise<PayrollRun> {
  const res = await apiClient.post(`/hr/payroll/runs/${id}/lock`);
  return res.data.data;
}

/** Re-open a locked run. Audited. */
export async function unlockPayrollRun(id: number): Promise<PayrollRun> {
  const res = await apiClient.post(`/hr/payroll/runs/${id}/unlock`);
  return res.data.data;
}

export async function getThirteenthMonth(
  year?: number,
): Promise<{ rows: ThirteenthMonthRow[]; year: number }> {
  const res = await apiClient.get("/hr/payroll/thirteenth-month", {
    params: year ? { year } : undefined,
  });
  return { rows: res.data.data, year: res.data.meta.year };
}

/** Exactly what a 13th-month run would pay — computed, saved nowhere (Slice B17). */
export async function previewThirteenthMonth(year?: number): Promise<{
  rows: ThirteenthMonthPayRow[];
  gross: number;
  tax: number;
  count: number;
  meta: {
    year: number;
    already_run: boolean;
    run_id: number | null;
    run_at: string | null;
    is_locked: boolean;
  };
}> {
  const res = await apiClient.get("/hr/payroll/thirteenth-month/preview", {
    params: year ? { year } : undefined,
  });
  return { ...res.data.data, meta: res.data.meta };
}

/** Pay it. Re-runnable: recomputes the same run in place, and refuses once locked. */
export async function runThirteenthMonth(year: number): Promise<{ id: number }> {
  const res = await apiClient.post("/hr/payroll/thirteenth-month/run", { year });
  return res.data.data;
}

export async function getMyNextPayday(): Promise<NextPayday> {
  const res = await apiClient.get("/hr/me/next-payday");
  return res.data.data;
}

/** Fetch a file endpoint as a blob and trigger a browser download (keeps auth headers). */
async function downloadFile(url: string, fallbackName: string): Promise<void> {
  const res = await apiClient.get(url, { responseType: "blob" });
  const disposition = res.headers["content-disposition"] as string | undefined;
  const name = disposition?.match(/filename="?([^"]+)"?/)?.[1] ?? fallbackName;

  const href = URL.createObjectURL(res.data as Blob);
  const link = document.createElement("a");
  link.href = href;
  link.download = name;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(href);
}

/** Employee: download own payslip PDF (server-gated to unlocked payslips). */
export function downloadMyPayslipPdf(id: number): Promise<void> {
  return downloadFile(`/hr/me/payslips/${id}/pdf`, `payslip-${id}.pdf`);
}

/** HR: download one employee's payslip PDF. */
export function downloadHrPayslipPdf(id: number): Promise<void> {
  return downloadFile(`/hr/payslips/${id}/pdf`, `payslip-${id}.pdf`);
}

/** HR: download a payroll run as a CSV (bank/disbursement file + remittance totals). */
export function downloadPayrollRunCsv(id: number): Promise<void> {
  return downloadFile(`/hr/payroll/runs/${id}/export`, `payroll-run-${id}.csv`);
}

export async function getMyPayslips(): Promise<PayslipSummary[]> {
  const res = await apiClient.get("/hr/me/payslips");
  return res.data.data;
}

export async function getPayslip(
  id: number,
): Promise<{ payslip: Payslip; ytd: PayslipYtd }> {
  const res = await apiClient.get(`/hr/me/payslips/${id}`);
  return { payslip: res.data.data, ytd: res.data.ytd };
}

/**
 * HR: one payslip in full. Unlike the employee endpoint this includes the employer's
 * counterpart contributions, which the resource withholds from a non-HR caller.
 */
export async function getHrPayslip(id: number): Promise<Payslip> {
  const res = await apiClient.get(`/hr/payslips/${id}`);
  return res.data.data;
}

/**
 * Pay an off-cycle correction (FIN-010).
 *
 * The window is when the correction is ISSUED, not a period it prices — what it corrects belongs
 * in `reason`. It does not recompute anything: it pays the stated amounts and withholds tax.
 */
export async function runAdjustment(input: {
  cycle_start: string;
  cycle_end: string;
  reason: string;
  lines: AdjustmentLine[];
}): Promise<PayrollRun> {
  const res = await apiClient.post("/hr/payroll/adjustment", input);
  return res.data.data;
}

/** Retroactive pay differences still waiting to be settled (FIN-009). */
export async function getRetroFlags(): Promise<RetroFlag[]> {
  const res = await apiClient.get("/hr/retro-flags");
  return res.data.data;
}

/** Mark one settled — the note is required, and says how it was paid. */
export async function resolveRetroFlag(id: number, note: string): Promise<RetroFlag> {
  const res = await apiClient.post(`/hr/retro-flags/${id}/resolve`, { note });
  return res.data.data;
}

// --- Disbursement (FIN-015) ------------------------------------------------------------------

/**
 * Build the payment batch for a completed run.
 *
 * Nothing about WHO or HOW MUCH is sent: both are derived from the run, because typed amounts
 * would be a second unreconciled source of truth for what payroll owes. `meta.skipped` says who
 * was left out and why — a batch that quietly omits three people looks identical to one that
 * does not, so the caller is expected to show it.
 */
export async function createPaymentBatch(input: {
  payroll_run_id: number;
  method: PaymentMethod;
  notes?: string | null;
}): Promise<{ batch: PaymentBatch; skipped: PaymentSkipped; reconciliation: PaymentReconciliation }> {
  const res = await apiClient.post("/hr/payment-batches", input);
  return {
    batch: res.data.data,
    skipped: res.data.meta.skipped,
    reconciliation: res.data.meta.reconciliation,
  };
}

/** Every batch, newest first. Optionally narrowed to one run. */
export async function getPaymentBatches(payrollRunId?: number): Promise<PaymentBatch[]> {
  const res = await apiClient.get("/hr/payment-batches", {
    params: payrollRunId ? { payroll_run_id: payrollRunId } : undefined,
  });
  return res.data.data;
}

/** One batch with every transfer in it, plus whether it accounts for the whole run. */
export async function getPaymentBatch(
  id: number,
): Promise<{ batch: PaymentBatch; reconciliation: PaymentReconciliation }> {
  const res = await apiClient.get(`/hr/payment-batches/${id}`);
  return { batch: res.data.data, reconciliation: res.data.meta.reconciliation };
}

/**
 * Download the file finance uploads to the bank.
 *
 * This is the point of no return: the server stamps `exported_at` on first download, after which
 * the batch can no longer be rebuilt. Once the file exists the company has to assume the bank has
 * it, and regenerating from changed payslips is how someone gets paid twice.
 */
export function downloadBankFile(id: number, reference: string): Promise<void> {
  return downloadFile(`/hr/payment-batches/${id}/bank-file`, `bank-file-${reference}.csv`);
}

/** Record what the bank did with one transfer. `settled` is the only outcome that means paid. */
export async function settlePayment(
  id: number,
  input: {
    status: PaymentStatus;
    reference?: string | null;
    settled_at?: string | null;
    failure_reason?: string | null;
  },
): Promise<Payment> {
  const res = await apiClient.patch(`/hr/payments/${id}`, input);
  return res.data.data;
}

/**
 * Apply the bank's return file to a batch.
 *
 * Previews unless `confirm` is set. This import moves payslips into `disbursed` — the assertion
 * the whole disbursement layer exists to make trustworthy — so it should not run on a file nobody
 * has read back.
 */
export async function reconcilePaymentBatch(
  id: number,
  file: File,
  confirm: boolean,
): Promise<PaymentReconcileResult> {
  const form = new FormData();
  form.append("file", file);
  if (confirm) form.append("confirm", "1");

  const res = await apiClient.post(`/hr/payment-batches/${id}/reconcile`, form);
  return res.data.data;
}

/** Deductions a thin payslip could not collect, still outstanding (FIN-005). */
export async function getDeductionArrears(): Promise<DeductionArrear[]> {
  const res = await apiClient.get("/hr/deduction-arrears");
  return res.data.data;
}
