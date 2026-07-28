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
  /** Slice 5 — a locked run can no longer be recomputed. */
  locked_at: string | null;
  locked_by: string | null;
  is_locked: boolean;
  /** Only present when the breakdown is loaded: every payslip is disbursed. */
  can_lock?: boolean;
  /** Slice 8 — the rules in force when this run executed. Detail view only. */
  policy_snapshot?: PolicySnapshot | null;
  breakdown?: PayrollBreakdownRow[];
}

/** One statutory contribution as it stood when a run executed. */
export interface PolicySnapshotContribution {
  type: string;
  is_active: boolean;
  config: Record<string, number | string | null> | null;
}

/**
 * Everything that priced a payroll run (Slice 8). `version` guards the shape — v1 runs
 * stored a flat map of deduction fields only.
 */
export interface PolicySnapshot {
  version?: number;
  captured_at?: string;
  attendance_policy?: Record<string, unknown>;
  contributions?: PolicySnapshotContribution[];
  payroll_setting?: Record<string, unknown>;
}

// Employee payslip HISTORY row — periods only, NO amounts. `unlocked` = viewable once
// the payroll run is finalized (no request needed). `hardcopy_status` is the state of
// any signed hard-copy request for the period. Figures come from the Payslip detail.
export type HardcopyStatus = "none" | "pending" | "approved" | "rejected";

export interface PayslipSummary {
  id: number;
  cycle_start: string;
  cycle_end: string;
  status: string;
  unlocked: boolean;
  // Net pay for the history row — present only once the slip is unlocked (finalized).
  net_pay: number | null;
  hardcopy_status: HardcopyStatus;
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
  overtime_pay: number;
  overtime_minutes: number;
  night_diff_pay: number;
  night_diff_minutes: number;
  rest_day_premium: number;
  rest_day_minutes: number;
  gross: number;
  sss: number;
  philhealth: number;
  pagibig: number;
  withholding_tax: number;
  total_deductions: number;
  net_pay: number;
  status: string;
  processed_by: string | null;
  // Attendance basis — how base_salary was derived (0 on older payslips).
  working_days: number;
  daily_rate: number;
  unpaid_days: number;
  late_minutes: number;
  undertime_minutes: number;
  late_deduction: number;
  undertime_deduction: number;
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
