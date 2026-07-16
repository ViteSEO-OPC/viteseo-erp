export interface PayrollPeriod {
  start: string;
  end: string;
  pay_date: string;
}

export interface PayGroup {
  frequency: string;
  employee_count: number;
  periods_per_year: number;
  current_period: PayrollPeriod;
}

export interface PayrollSettings {
  frequency: string;
  deduction_timing: string; // every | first | last
  tax_timing: string;
  anchor_date: string | null;
  pay_delay_days: number;
  periods_per_year: number;
  current_period: PayrollPeriod;
  pay_groups: PayGroup[];
}

export interface PayrollBreakdownRow {
  id: number;
  employee: string | null;
  department: string | null;
  gross: number;
  deductions: number;
  net: number;
  status: string;
}

export interface PayrollRun {
  id: number;
  cycle_start: string;
  cycle_end: string;
  frequency: string;
  status: string;
  total_gross: number;
  total_deductions: number;
  total_net: number;
  employee_count: number;
  run_at: string | null;
  run_by: string | null;
  breakdown?: PayrollBreakdownRow[];
}

// Employee payslip HISTORY row — periods + request state only, NO amounts. Figures
// are served by the gated detail (Payslip) once an approved request unlocks them.
export type PayslipRequestStatus = "none" | "pending" | "approved" | "rejected";

export interface PayslipSummary {
  id: number;
  cycle_start: string;
  cycle_end: string;
  status: string;
  request_status: PayslipRequestStatus;
  unlocked: boolean;
}

export interface Payslip {
  id: number;
  cycle_start: string;
  cycle_end: string;
  employee: { id: number; name: string | null };
  base_salary: number;
  reimbursements: number;
  allowances: number;
  holiday_premium: number;
  gross: number;
  sss: number;
  philhealth: number;
  pagibig: number;
  withholding_tax: number;
  total_deductions: number;
  net_pay: number;
  status: string;
  processed_by: string | null;
}

export interface PayslipYtd {
  gross: number;
  deductions: number;
  net: number;
  thirteenth_month: number;
}

// One row of the HR 13th-month view: basic earned this year ÷ 12, per employee.
export interface ThirteenthMonthRow {
  employee_id: number;
  employee: string | null;
  department: string | null;
  periods: number;
  ytd_basic: number;
  thirteenth_month: number;
}

// The caller's own next pay date, resolved from their pay group.
export interface NextPayday {
  pay_date: string;
  frequency: string;
}
