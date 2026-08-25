import { apiClient } from "@/core/api-client";
import type {
  AttendanceException,
  NextPayday,
  PayrollRun,
  PayrollSettings,
  Payslip,
  PayslipSummary,
  PayslipYtd,
  ThirteenthMonthRow,
  ThirteenthMonthPayRow,
  AmendmentKind,
  AmendmentLine,
  RerunPreviewMeta,
  RerunPreviewRow,
  RetroFlag,
  DeductionArrear,
  PaymentBatch,
  PaymentMethod,
  PaymentReconciliation,
  PaymentReconcileResult,
  PaymentSkipped,
  PaymentStatus,
  Payment,
  MinimumWageRow,
  MinimumWageMeta,
  MinimumWageApplyResult,
} from "@/modules/hr/types/payroll";

export async function getPayrollSettings(): Promise<PayrollSettings> {
  const res = await apiClient.get("/hr/payroll/settings");
  return res.data.data;
}

export async function updatePayrollSettings(input: {
  frequency: string;
  deduction_timing?: string;
  tax_timing?: string;
  /** Which cutoff the monthly allowance lands on: every | first | last. */
  allowance_timing?: string;
  /** Null clears the ceiling (allowances fully tax-free). */
  other_benefits_annual_cap?: number | null;
  /**
   * The date these settings take effect. Omitted means today. Payroll resolves settings
   * as of the cycle end, so this is what stops a change reaching back into periods already run.
   */
  effective_from?: string;
  /** The regional minimum daily wage. Null clears it (warns about nothing). */
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
  /**
   * Proceed past a minimum-wage breach. The reason is required when this is set: it is
   * the record that somebody decided to run anyway, rather than the button simply having been
   * clicked. Omitted when there is nothing to acknowledge.
   */
  minimum_wage_ack?: boolean;
  minimum_wage_ack_reason?: string;
}): Promise<PayrollRun> {
  const res = await apiClient.post("/hr/payroll/run", input);
  return res.data.data;
}

/** Who this run would pay below the regional floor. Read-only; prices nothing. */
export async function getMinimumWageCheck(input: {
  frequency: string;
  cycle_start?: string;
  cycle_end?: string;
}): Promise<{ rows: MinimumWageRow[]; meta: MinimumWageMeta }> {
  const res = await apiClient.get("/hr/payroll/minimum-wage-check", { params: input });
  return { rows: res.data.data, meta: res.data.meta };
}

/**
 * Raise the named employees to the salary that clears the floor, as dated salary
 * changes. The ids are explicit: a salary change is not something to apply to a list nobody read.
 */
export async function applyMinimumWageAdjustments(input: {
  employee_ids: number[];
  effective_date: string;
  reason?: string;
  acknowledge_repricing?: boolean;
}): Promise<MinimumWageApplyResult> {
  const res = await apiClient.post("/hr/payroll/minimum-wage-adjustments", input);
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

/**
 * Seal a fully-disbursed run so it can never be recomputed.
 *
 * Refused with a 422 on `acknowledge_overpayment` when a correction dropped a paid payslip below
 * what was transferred — money the company is out and cannot claw back without the employee's
 * written consent. Pass the flag to lock it anyway; that is a confirmation, not a default.
 */
export async function lockPayrollRun(
  id: number,
  acknowledgeOverpayment = false,
): Promise<PayrollRun> {
  const res = await apiClient.post(
    `/hr/payroll/runs/${id}/lock`,
    acknowledgeOverpayment ? { acknowledge_overpayment: true } : {},
  );
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

/** Exactly what a 13th-month run would pay — computed, saved nowhere. */
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

/**
 * HR: the same run as a styled workbook — the breakdown laid out to be read, with the full
 * register (employer shares, override marker) on a second sheet. Same endpoint as the CSV;
 * the server picks the format off the query string.
 */
export function downloadPayrollRunXlsx(id: number): Promise<void> {
  return downloadFile(`/hr/payroll/runs/${id}/export?format=xlsx`, `payroll-run-${id}.xlsx`);
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

/** Set ONE line to ONE figure across a whole period — a charge waived for everybody. */
export async function correctRunLine(
  runId: number,
  input: {
    target_key: string;
    amount?: number | null;
    label?: string | null;
    reason: string;
    issued_on?: string | null;
    employee_ids?: number[] | null;
  },
): Promise<{ run: PayrollRun; applied: number; skipped: string[] }> {
  const res = await apiClient.post(`/hr/payroll/runs/${runId}/line-corrections`, input);

  return {
    run: res.data.data,
    applied: res.data.meta?.applied ?? 0,
    skipped: res.data.meta?.skipped ?? [],
  };
}

/** Correct ONE line on ONE payslip — the engine said ₱500 for Late, HR says ₱300. */
export async function correctPayslipLine(
  payslipId: number,
  input: {
    target_key: string;
    amount?: number | null;
    label?: string | null;
    reason: string;
    issued_on?: string | null;
  },
): Promise<Payslip> {
  const res = await apiClient.post(`/hr/payslips/${payslipId}/line-corrections`, input);
  return res.data.data;
}

/** Put a corrected line back to what the engine computed. */
export async function removePayslipLineCorrection(
  payslipId: number,
  correctionId: number,
): Promise<Payslip> {
  const res = await apiClient.delete(`/hr/payslips/${payslipId}/line-corrections/${correctionId}`);
  return res.data.data;
}

/**
 * "Seen it, my figure still stands." Clears the drift warning without touching the payslip —
 * re-stamps the engine figure the correction is measured against.
 */
export async function acknowledgePayslipLineCorrection(
  payslipId: number,
  correctionId: number,
): Promise<Payslip> {
  const res = await apiClient.post(
    `/hr/payslips/${payslipId}/line-corrections/${correctionId}/acknowledge`,
  );
  return res.data.data;
}

/** Correct a period on its OWN payslips. */
export async function amendPayrollRun(
  runId: number,
  input: {
    reason: string;
    issued_on?: string | null;
    kind?: AmendmentKind;
    lines: AmendmentLine[];
  },
): Promise<PayrollRun> {
  const res = await apiClient.post(`/hr/payroll/runs/${runId}/amendments`, input);
  return res.data.data;
}

/**
 * What recomputing a period WOULD change, per employee.
 *
 * Read-only, and expensive: the server computes the whole run for real and rolls it back, which is
 * the only honest way to describe a recompute. Fetch it when the confirmation opens, not on a hover.
 */
export async function getRerunPreview(
  runId: number,
): Promise<{ rows: RerunPreviewRow[]; meta: RerunPreviewMeta }> {
  const res = await apiClient.get(`/hr/payroll/runs/${runId}/rerun-preview`);
  return { rows: res.data.data, meta: res.data.meta };
}

/**
 * Recompute a period that has already been run. The reason is required — this replaces figures that
 * were already settled — and the cycle comes from the run, so the wrong period cannot be recomputed
 * by mistyping a date.
 */
export async function rerunPayrollRun(runId: number, reason: string): Promise<PayrollRun> {
  const res = await apiClient.post(`/hr/payroll/runs/${runId}/rerun`, { reason });
  return res.data.data;
}

/** Retroactive pay differences still waiting to be settled. */
export async function getRetroFlags(): Promise<RetroFlag[]> {
  const res = await apiClient.get("/hr/retro-flags");
  return res.data.data;
}

/** Mark one settled — the note is required, and says how it was paid. */
export async function resolveRetroFlag(id: number, note: string): Promise<RetroFlag> {
  const res = await apiClient.post(`/hr/retro-flags/${id}/resolve`, { note });
  return res.data.data;
}

// --- Disbursement ------------------------------------------------------------------

/** Build the payment batch for a completed run. */
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

/** Download the file finance uploads to the bank. */
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

/** Apply the bank's return file to a batch. */
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

/** Deductions a thin payslip could not collect, still outstanding. */
export async function getDeductionArrears(): Promise<DeductionArrear[]> {
  const res = await apiClient.get("/hr/deduction-arrears");
  return res.data.data;
}

/**
 * Item 12 — attendance exceptions for a cycle, read before the run turns them into money.
 * Derived on every call, so there is nothing to resolve here: an entry disappears when the record
 * behind it is corrected on its own screen.
 */
export async function getAttendanceExceptions(
  start: string,
  end: string,
): Promise<AttendanceException[]> {
  const res = await apiClient.get("/hr/payroll/attendance-exceptions", {
    params: { start, end },
  });
  return res.data.data;
}
