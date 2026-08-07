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
  /** FIN-030 — regional minimum DAILY wage from the applicable DOLE wage order. Null = not set. */
  minimum_daily_wage: number | null;
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
  /**
   * FIN-011 — the small grey qualifier beside the label ("2.5 hrs", "Tax-exempt", "6 day(s)").
   * Optional because `allowances_breakdown` predates it and carries none.
   */
  note?: string | null;
}

/**
 * The document's subtotals, derived by the API so the screen and the PDF can't disagree.
 * They satisfy `compensation - deductions - government = net_pay`.
 *
 * `uncollected` was a term in that identity until FIN-053. The stored deduction components used
 * to hold the full computed amount while the total was capped at gross, so the shortfall had to
 * be credited back for the document to close. The components now hold what was actually
 * collected — `uncollected` is a disclosure, and adding it back would overstate net pay.
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
  /** Deductions pay couldn't cover. Disclosed, not summed into net — see the note above. */
  uncollected: number;
  /**
   * Which obligations that shortfall belongs to (FIN-053), keyed by column name. Empty when
   * nothing went short, and on payslips issued before the split — the migration doesn't backfill.
   */
  uncollected_breakdown: Partial<Record<PayslipDeductionKey, number>>;
}

/** The deduction columns a shortfall can land on, in the order the cap is allocated. */
export type PayslipDeductionKey =
  | "sss"
  | "philhealth"
  | "pagibig"
  | "withholding_tax"
  | "other_deductions";

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
  // FIN-011 — every earning as a named line, built by PayslipResource so the screen and the PDF
  // render the same list. Sums to `totals.compensation` for every run type.
  earnings_breakdown: PayslipLine[];
  // FIN-032 — interest collected on loans this period, itemised in other_deductions_breakdown too.
  loan_interest: number;
  // Stored all along and never serialised until FIN-011 — a 13th-month payslip showed a gross with
  // every earnings line at zero and a tax figure with no visible basis.
  thirteenth_month: number;
  other_benefits: number;
  taxable_pay: number;
  /** Which kind of run produced this slip, and the words the header prints for it. */
  run_type: "regular" | "thirteenth_month" | "adjustment" | "final_pay";
  run_type_label: string;
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
  /**
   * Slice B19 — the monthly salary figure each contribution was actually charged on. HR-only.
   * Null on payslips run before the column existed, and on the 13th-month, final-pay and
   * adjustment runs, which take no statutory contributions at all. Keyed by type because the
   * three no longer share one number: PhilHealth is charged on basic salary by law while SSS
   * can follow actual earnings.
   */
  contribution_bases?: Partial<Record<"sss" | "philhealth" | "pagibig", number>> | null;
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

/**
 * One line of an off-cycle adjustment run (FIN-010) — who is paid a correction, and how much.
 */
export interface AdjustmentLine {
  employee_id: number;
  amount: number;
  note?: string | null;
}

/**
 * An outstanding retroactive pay difference (FIN-009).
 *
 * Written when a leave/overtime/half-day/early-out decision lands on a period payroll has already
 * settled. `owed` is paid with an adjustment run; `overpaid` has no automated path, because
 * recovering paid wages is a deduction that needs the employee's consent (Art. 113).
 */
export interface RetroFlag {
  id: number;
  employee: { id: number | null; name: string | null };
  direction: "owed" | "overpaid";
  reason: string;
  /** The settled cutoff the decision reached into, e.g. "2026-03-01 – 2026-03-15". */
  period: string | null;
  /** Which kind of request caused it — OvertimeRequest, LeaveRequest, … */
  source: string;
  source_id: number;
  resolved_at: string | null;
  resolution_note: string | null;
  created_at: string | null;
}

// --- Disbursement (FIN-015) ------------------------------------------------------------------

/** How the money leaves the company. One method per batch — a bank file is per payment rail. */
export type PaymentMethod = "bank_transfer" | "cash" | "check";

/**
 * pending   built, not yet sent to the bank
 * exported  the bank file has been generated and downloaded — the point of no return
 * settled   every payment in it confirmed
 * partial   some settled, some failed or still outstanding
 */
export type PaymentBatchStatus = "pending" | "exported" | "settled" | "partial";

/**
 * pending   in a batch, not yet confirmed by the bank
 * settled   confirmed — the ONLY state that means money actually moved
 * failed    the bank rejected it
 * returned  it left and came back
 */
export type PaymentStatus = "pending" | "settled" | "failed" | "returned";

export interface Payment {
  id: number;
  payslip_id: number;
  amount: number;
  method: PaymentMethod;
  status: PaymentStatus;
  bank_name: string | null;
  /** Masked to the last four digits. The full number is never served to a screen (FIN-022). */
  bank_account_masked: string | null;
  bank_account_type: string | null;
  /** Both null until reconciliation — the gap between "we sent it" and "it arrived". */
  reference: string | null;
  settled_at: string | null;
  failure_reason: string | null;
  employee?: {
    id: number;
    employee_no: string | null;
    name: string | null;
    department: string | null;
  };
}

export interface PaymentBatch {
  id: number;
  reference: string;
  method: PaymentMethod;
  status: PaymentBatchStatus;
  total_amount: number;
  payment_count: number;
  notes: string | null;
  /** Non-null means the bank file has been downloaded and the batch can no longer be rebuilt. */
  exported_at: string | null;
  exported_by?: string | null;
  created_by?: string | null;
  created_at: string | null;
  payroll_run?: {
    id: number;
    cycle_start: string;
    cycle_end: string;
    frequency: string;
    total_net: number;
  };
  payments?: Payment[];
}

/** Who was left out of the batch, and why. Shown rather than swallowed — see the API note. */
export interface PaymentSkipped {
  already_disbursed: number;
  already_batched: number;
  zero_net: number;
  missing_bank_details: number;
}

/** Does this batch account for the whole run? The check finance makes before money moves. */
export interface PaymentReconciliation {
  balanced: boolean;
  batch_total: number;
  run_total: number;
  difference: number;
  unpaid_payslips: number;
}

/** What a reconciliation file would do (preview) or did (applied). */
export interface PaymentReconcileResult {
  applied: boolean;
  settled: number;
  failed: number;
  /** Rows that matched no transfer, or carried a status we refuse to guess at. */
  unmatched: string[];
  rows: number;
}

/**
 * A statutory or benefit deduction a thin payslip could not collect, still outstanding (FIN-005).
 *
 * Recovery is automatic — the next run with room takes it, statutory first. This type exists for
 * the amounts that CANNOT be recovered: an employee on long unpaid leave, or one who has left, for
 * whom there is no future payslip. The company still owes the agency the full contribution.
 */
export interface DeductionArrear {
  employee: {
    id: number;
    name: string | null;
    department: string | null;
    /** No future payslip exists, so payroll can never recover this — a person must. */
    separated: boolean;
  };
  obligations: { obligation: string; label: string; outstanding: number }[];
  total_outstanding: number;
}

/**
 * FIN-027 — the withholding-tax table, exposed so it can be checked against the BIR schedule.
 *
 * `max_annual` is derived server-side (the next band's floor − 0.01) because the table stores only
 * floors, while the BIR prints ranges — and a range is what makes it checkable. Null on the top band.
 */
export interface TaxBand {
  min_annual: number;
  max_annual: number | null;
  base_tax: number;
  rate_on_excess: number;
}

export interface TaxTable {
  as_of: string;
  /** When the version answering for `as_of` took effect; null when no version covers the date. */
  effective_from: string | null;
  note: string | null;
  /** True when `as_of` precedes every version and the earliest table was used instead. */
  is_fallback: boolean;
  bands: TaxBand[];
  versions: { effective_from: string | null; note: string | null }[];
}

/**
 * FIN-030b — one employee this run would pay below the regional minimum daily wage.
 *
 * The rate is DERIVED (salary ÷ the basis on the attendance policy), which is the whole reason the
 * row exists: nobody typing a monthly figure can see what it implies per day, and the per-day
 * figure is what a wage order governs.
 */
export interface MinimumWageRow {
  employee: { id: number; name: string | null; employee_no: string | null; job_title: string | null };
  base_salary: number;
  daily_rate: number;
  basis: "fixed_divisor" | "period_workdays";
  divisor: number | null;
  workdays: number;
  minimum: number | null;
  below: boolean;
  /** `below` minus anyone marked exempt — what the warning and the run gate read. */
  flagged: boolean;
  shortfall: number | null;
  /** The monthly base salary that would clear the floor. */
  compliant_salary: number | null;
  exempt: boolean;
  exempt_reason: string | null;
}

export interface MinimumWageMeta {
  /** Null when no wage order has been entered — nothing is flagged, and nothing is guessed. */
  minimum: number | null;
  basis: "fixed_divisor" | "period_workdays" | null;
  divisor: number | null;
  cycle: { start: string; end: string };
  checked: number;
  flagged_count: number;
  exempt_count: number;
}

export interface MinimumWageApplyResult {
  applied: { employee_id: number; name: string | null; from: number; to: number }[];
  skipped: { employee_id: number; name: string | null; reason: string }[];
  /** Periods already settled when the raise takes effect — recorded as back pay owed (FIN-009). */
  back_pay_flagged: number;
}
