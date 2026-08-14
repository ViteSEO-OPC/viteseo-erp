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
  /**
   * FIN-048 — which cutoff the MONTHLY allowance lands on. `every` splits it across the month's
   * cutoffs (the long-standing behaviour, which is why ₱1,500/month reads as ₱750 twice on a
   * semi-monthly payslip); `first`/`last` pay it whole on one of them.
   */
  allowance_timing: string;
  anchor_date: string | null;
  pay_delay_days: number;
  /** Slice 10 -- annual ceiling above which allowances become taxable. null = no ceiling. */
  /** ₱90,000 statutory: 13th month pay AND other benefits, NOT de minimis (B16). */
  other_benefits_annual_cap: number | null;
  /** FIN-030 — regional minimum DAILY wage from the applicable DOLE wage order. Null = not set. */
  minimum_daily_wage: number | null;
  /**
   * FIN-046 — how many employees have NO explicit `pay_frequency` and therefore follow this
   * default. Changing `frequency` moves every one of them into a different pay group, writing
   * nothing to their records — so the number has to be visible BEFORE the change is saved.
   */
  inheriting_employee_count?: number;
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
  rules_drift?: RulesDriftEntry[];
  /**
   * FIN-051 — payslips this run left untouched because they were already disbursed. Present on
   * the response to a run that just executed; null when a run is merely read back.
   */
  skipped_disbursed?: number | null;
  breakdown?: PayrollBreakdownRow[];
}

/**
 * How the year-end tax settlement on a December (or final-pay) payslip was arrived at (FIN-054).
 * Absent on every other payslip. All figures are for the whole tax year, not the period.
 */
export interface TaxAnnualisationBasis {
  year: number;
  /** The year's total taxable pay, across every payslip issued — the BIR 2316 figure. */
  year_taxable: number;
  /** Annual tax actually due on that, from the graduated table. */
  annual_tax_due: number;
  /** What had already been withheld across the year before this payslip. */
  previously_withheld: number;
  /** What this payslip withholds once the year is settled. Negative = refund. */
  settled_this_period: number;
}

/** How one statutory contribution on a payslip was arrived at (FIN-049). */
export interface ContributionBasis {
  overridden: boolean;
  basis?: "bracket" | "percentage";
  rate?: number;
  /** The salary or earnings figure the contribution was computed from. */
  salary?: number;
  /** The figure the rate was actually applied to, after any cap, floor or bracket MSC. */
  charged_on?: number;
  capped?: boolean;
  floored?: boolean;
}

/**
 * One rule that has changed since the run executed and WOULD price this cycle differently on a
 * re-run. The snapshot stays frozen; this is what reconciles it with the Company rules screen.
 */
export interface RulesDriftEntry {
  group: "attendance_policy" | "payroll_setting" | "contributions" | string;
  key: string;
  was: unknown;
  now: unknown;
}

/**
 * Whether the company operates a statutory contribution at all. Its `config` column is DEAD —
 * a pre-Slice-B9 leftover that is no longer written and no longer prices anything. The rates
 * live on `contribution_versions`; read them from there, never from here.
 */
export interface PolicySnapshotContribution {
  type: string;
  is_active: boolean;
  config: Record<string, number | string | null> | null;
}

/** The dated rate version of one contribution that was actually in force for the cycle. */
export interface PolicySnapshotContributionVersion {
  type: string;
  basis: "percentage" | "bracket" | string;
  employee_rate: string | number | null;
  employer_rate: string | number | null;
  salary_floor: string | number | null;
  salary_cap: string | number | null;
  employee_amount_cap: string | number | null;
  employer_amount_cap: string | number | null;
  fixed_employer_add_on: string | number | null;
  add_on_min_salary: string | number | null;
  effective_from: string | null;
}

/**
 * Everything that priced a payroll run (Slice 8). `version` guards the shape — v1 runs
 * stored a flat map of deduction fields only.
 */
export interface PolicySnapshot {
  version?: number;
  captured_at?: string;
  attendance_policy?: Record<string, unknown>;
  /** Enrolment only ("does the company operate this?") — NOT the rates. */
  contributions?: PolicySnapshotContribution[];
  /** The rates that actually priced the run. Absent on runs from before Slice B3a. */
  contribution_versions?: PolicySnapshotContributionVersion[];
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
  /**
   * Which table this line belongs in. The document prints allowances BELOW the
   * net-of-contributions subtotal rather than among the earnings, so the split is decided by the
   * API — see PayslipResource::earningsBreakdown. Absent on `allowances_breakdown` and
   * `other_deductions_breakdown`, which are already single-purpose lists.
   */
  kind?: "earning" | "allowance";
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
  /** Full period basic + every earning line, allowances included. */
  compensation: number;
  /** The Compensation table's own total: `compensation` with allowances taken out. */
  earnings: number;
  /** The Allowances table's total, summed from the allowance-kind earnings lines. */
  allowances: number;
  /** The absence charge less the late/undertime portion of it. */
  absences: number;
  /** The late + undertime portion, clamped so the two absence rows can't over-total. */
  late_undertime: number;
  /** Absence charge + benefit shares + loans. */
  deductions: number;
  /** "Total Current Amount" — `earnings - deductions`, before any contribution. */
  current: number;
  /** SSS + PhilHealth + Pag-IBIG + withholding tax. */
  government: number;
  /** "Initial Net Salary" — `current - government`, still without allowances. */
  initial_net: number;
  /** Deductions pay couldn't cover. Disclosed, not summed into net — see the note above. */
  uncollected: number;
  /**
   * Which obligations that shortfall belongs to (FIN-053), keyed by column name. Empty when
   * nothing went short, and on payslips issued before the split — the migration doesn't backfill.
   */
  uncollected_breakdown: Partial<Record<PayslipDeductionKey, number>>;
}

/**
 * One row of the Deductions table: a REASON, its dates, and its money.
 *
 * The pesos are apportioned by the API from the two authoritative figures rather than multiplied
 * out from `days × daily_rate` — basic pay clamps at zero, so on a heavily-absent period the
 * multiplied figures each look defensible and their column does not add up to the total printed
 * beneath it. See PayslipResource::deductionsBreakdown.
 */
export interface PayslipDeductionRow {
  /** `absent` · `undertime` · `half_day` · `late` · `suspended_unpaid` · … · `other`. */
  key: string;
  name: string;
  /** How many dated occurrences — the red badge beside the label. 0 hides it. */
  count: number;
  days: number;
  /** Late and undertime only; null on every other reason. */
  minutes: number | null;
  dates: { date: string | null; detail: string | null }[];
  amount: number;
}

/** The deduction columns a shortfall can land on, in the order the cap is allocated. */
export type PayslipDeductionKey =
  | "sss"
  | "philhealth"
  | "pagibig"
  | "withholding_tax"
  | "other_deductions";

/** One day the period charged for, and why. */
export interface AttendanceChargeLine {
  date: string;
  /**
   * absent · half_day · leave_unpaid · suspended_unpaid · holiday_forfeited · not_employed ·
   * early_out · late_penalty (the unpaid group), or late · undertime (the time group).
   */
  kind: string;
  days: number;
  detail: string | null;
}

/**
 * How minutes become money: the charging mode, the tier ladder that can make 20 minutes and
 * 200 minutes cost the same, and the DOLE day the fraction is taken against.
 */
export interface DeductionRule {
  late_mode: string;
  late_tiers: { over: number; hours: number }[];
  undertime_mode: string;
  hours_per_day: number;
  rounding: string;
  block_minutes: number;
}

/**
 * The four steps behind a withholding figure: this period's taxable pay, the contributions
 * netted off it, the annualised projection, and the bracket that projection landed in.
 */
export interface TaxBasis {
  taxable_monthly: number;
  contributions_monthly: number;
  annual_taxable: number;
  annual_tax: number;
  band: { min: number; base: number; rate: number } | null;
  monthly_tax: number;
  timing_factor: number;
  timing: string;
}

/**
 * Where a slip sits among its calendar month's cutoffs, from the SCHEDULE rather than from the
 * slips that happen to exist — on the month's first cutoff the second has not been run yet.
 * Null on every run type but `regular`: a 13th-month or final-pay run is not a cutoff of anything.
 */
export interface PayslipCutoff {
  /** 0-based position within the month. */
  index: number;
  /** How many cutoffs the month has: 1 monthly, 2 semi-monthly, 4–5 weekly. */
  count: number;
  is_last: boolean;
  /** "1st Cut-Off" / "2nd Cut-Off", or "Cut-Off 3 of 4" beyond two. Null when the month has one. */
  label: string | null;
}

/** One cutoff restated inside the month roll-up. */
export interface PayslipMonthPeriod {
  label: string;
  cycle_start: string | null;
  cycle_end: string | null;
  /** That cutoff's own "Total Current Amount". */
  current_amount: number;
  net_pay: number;
  is_current: boolean;
}

/**
 * The whole month, carried by its LAST cutoff (FIN — payslip redesign).
 *
 * With the contribution/tax/allowance timings set to `last`, the month's deductions all land on
 * one slip and no single slip is a picture of the month. This is that picture: every cutoff
 * restated and carried through the same four steps.
 *
 * Summed across the month's slips, never inferred from this one — that shortcut holds only under
 * `last` and under-reports the month under `every`. The identity:
 *
 *     total_current - government = initial_net
 *     initial_net + allowances = total_month = the month's net pay
 *     total_month - already_paid = this slip's own net pay
 *
 * Null unless this is the last cutoff of a month with more than one, where an earlier one ran.
 */
export interface PayslipMonthSummary {
  periods: PayslipMonthPeriod[];
  total_current: number;
  government: number;
  allowances: number;
  initial_net: number;
  total_month: number;
  /** What earlier cutoffs already paid out. */
  already_paid: number;
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
  // FIN-011 — every earning as a named line, built by PayslipResource so the screen and the PDF
  // render the same list. Sums to `totals.compensation` for every run type.
  earnings_breakdown: PayslipLine[];
  // FIN-032 — interest collected on loans this period, also itemised in other_deductions_breakdown.
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
  /** The Deductions table, one row per reason with the dates behind it. */
  deductions_breakdown: PayslipDeductionRow[];
  total_deductions: number;
  net_pay: number;
  totals: PayslipTotals;
  /** Which cutoff of the month this is. Null on non-regular runs. */
  cutoff: PayslipCutoff | null;
  /** The month rolled up — present on the last cutoff of a multi-cutoff month only. */
  month_summary: PayslipMonthSummary | null;
  /** Company counterpart contributions. HR-only — absent from an employee's own response. */
  employer_sss?: number;
  employer_philhealth?: number;
  employer_pagibig?: number;
  /**
   * FIN-049 — what each statutory contribution was charged on, keyed by type. `salary` is the
   * figure it was computed FROM, `charged_on` the figure the rate was applied TO; they differ
   * whenever a cap, floor or SSS bracket sits between them, which is precisely when a deduction
   * looks wrong. Absent for payslips predating the snapshot, and `{ overridden: true }` alone
   * when a per-employee peso amount replaced the rate.
   */
  contribution_basis?: Record<string, ContributionBasis>;
  /**
   * FIN-054 — the year's figures behind a December settlement. Present only on the payslip that
   * closes a tax year (or a final-pay slip), null everywhere else.
   */
  tax_annualisation_basis?: TaxAnnualisationBasis | null;
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
  /**
   * The same rate said three ways, for the document's header block. `hourly` divides by the run's
   * own `hours_per_day` divisor rather than a hardcoded 8, so it reconciles with the
   * late/undertime charge further down the same document.
   */
  rates: { daily: number; hourly: number; half_day: number };
  unpaid_days: number;
  late_minutes: number;
  undertime_minutes: number;
  late_deduction: number;
  undertime_deduction: number;
  /** Whole days charged by the per-N-lates penalty (item 13b.13). */
  late_penalty_days: number;
  /**
   * FIN-055 — the per-date ledger behind `unpaid_days` and `late_minutes`, grouped by the two
   * questions people actually ask. Null on payslips issued before the column existed, which the
   * document renders by simply not showing the note.
   *
   * The `days` across `unpaid` sum to `unpaid_days`. `late` entries can carry `days: 0` — the
   * minutes happened even where the policy charges nothing for them.
   */
  attendance_lines: {
    unpaid: AttendanceChargeLine[];
    late: AttendanceChargeLine[];
  } | null;
  /**
   * FIN-055 — the rule that turned late/undertime minutes into a fraction of a day, from the
   * run's own policy snapshot. Null when nothing was charged, or on runs predating the snapshot.
   */
  deduction_rule: DeductionRule | null;
  /** FIN-055 — how `withholding_tax` was arrived at. Null when nothing was withheld. */
  tax_basis: TaxBasis | null;
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
