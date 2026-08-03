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
  /** Slice 10 -- annual ceiling above which allowances become taxable. null = no ceiling. */
  /** ₱90,000 statutory: 13th month pay AND other benefits, NOT de minimis (B16). */
  other_benefits_annual_cap: number | null;
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

/** One named money line on the payslip (allowance component, benefit share, loan). */
export interface PayslipLine {
  name: string;
  amount: number;
}

/**
 * The document's subtotals, derived by the API so the screen and the PDF can't disagree.
 * They satisfy `compensation - deductions - government + uncollected = net_pay`.
 */
export interface PayslipTotals {
  /** Full period basic + every earning line. */
  compensation: number;
  /** The absence charge less the late/undertime portion of it. */
  absences: number;
  /** The late + undertime portion, clamped so the two absence rows can't over-total. */
  late_undertime: number;
  /** Absence charge + benefit shares + loans. */
  deductions: number;
  /** SSS + PhilHealth + Pag-IBIG + withholding tax. */
  government: number;
  /** Deductions that pay couldn't cover, credited back so the document reconciles. */
  uncollected: number;
}

export interface Payslip {
  id: number;
  cycle_start: string;
  cycle_end: string;
  /** When the period pays out: cycle end + the run's pay delay. */
  pay_date: string | null;
  frequency: string | null;
  /** When the run executed — distinct from pay_date. */
  issued_at: string | null;
  employee: {
    id: number;
    name: string | null;
    employee_no: string | null;
    job_title: string | null;
    department: string | null;
    address: string | null;
    tin: string | null;
    sss_number: string | null;
    philhealth_number: string | null;
    pagibig_number: string | null;
  };
  /** Full period basic BEFORE absences — what the payslip shows as "Basic Pay". */
  period_base: number;
  /** Basic pay after absences. `period_base - absence_deduction`. */
  base_salary: number;
  /** The absence charge actually applied to basic pay. */
  absence_deduction: number;
  reimbursements: number;
  allowances: number;
  /** Allowances split by component, snapshot at run time. */
  allowances_breakdown: PayslipLine[];
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
  /** Slice 11a — recurring benefit shares (HMO, union dues) and loans. Post-tax. */
  other_deductions: number;
  other_deductions_breakdown: PayslipLine[];
  total_deductions: number;
  net_pay: number;
  totals: PayslipTotals;
  /** Company counterpart contributions. HR-only — absent from an employee's own response. */
  employer_sss?: number;
  employer_philhealth?: number;
  employer_pagibig?: number;
  /**
   * Slice B7 — which statutory deductions came from a per-employee amount on the profile rather
   * than the government rates. HR-only, and empty on payslips run before the column existed.
   * An override moves the employee half only, so these lines do not pair with the employer ones.
   */
  contribution_overrides?: ("sss" | "philhealth" | "pagibig")[];
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
  /** Whole days charged by the per-N-lates penalty (item 13b.13). */
  late_penalty_days: number;
  /** Deductions that gross couldn't cover (item 13a.1). */
  deduction_carryover: number;
}

export interface PayslipYtd {
  gross: number;
  deductions: number;
  net: number;
  thirteenth_month: number;
}

// One row of the HR 13th-month view: basic earned this year ÷ 12, per employee.
/** What a 13th-month RUN would pay one employee (Slice B17) — not the projection. */
export interface ThirteenthMonthPayRow {
  employee_id: number;
  name: string | null;
  thirteenth_month: number;
  /** The year's allowance excess, which shares the ₱90,000 exemption with it. */
  other_benefits: number;
  taxable_excess: number;
  tax: number;
  net: number;
}

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
