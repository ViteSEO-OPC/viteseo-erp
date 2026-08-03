import { apiClient } from "@/core/api-client";
import type {
  NextPayday,
  PayrollRun,
  PayrollSettings,
  Payslip,
  PayslipSummary,
  PayslipYtd,
  ThirteenthMonthRow,
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
