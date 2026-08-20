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
   * Which cutoff the MONTHLY allowance lands on. `every` splits it across the month's
   * cutoffs (the long-standing behaviour, which is why ₱1,500/month reads as ₱750 twice on a
   * semi-monthly payslip); `first`/`last` pay it whole on one of them.
   */
  allowance_timing: string;
  anchor_date: string | null;
  pay_delay_days: number;
  /** Annual ceiling above which allowances become taxable. null = no ceiling. */
  /** ₱90,000 statutory: 13th month pay AND other benefits, NOT de minimis. */
  other_benefits_annual_cap: number | null;
  /** Regional minimum DAILY wage from the applicable DOLE wage order. Null = not set. */
  minimum_daily_wage: number | null;
  /**
   * How many employees have NO explicit `pay_frequency` and therefore follow this
   * default. Changing `frequency` moves every one of them into a different pay group, writing
   * nothing to their records — so the number has to be visible BEFORE the change is saved.
   */
  inheriting_employee_count?: number;
  periods_per_year: number;
  current_period: PayrollPeriod;
  pay_groups: PayGroup[];
}

export interface PayrollBreakdownRow {
  /** The PAYSLIP id — what the row's actions act on. */
  id: number;
  /** The employee id, for addressing a correction to this period's payslip. */
  employee_id: number;
  employee: string | null;
  department: string | null;
  gross: number;
  deductions: number;
  net: number;
  status: string;
  /** What has actually been transferred. Null until the payslip is marked paid. */
  disbursed: number | null;
  /**
   * What is still owed on this payslip — `net - disbursed`. Equal to `net` while unpaid and zero
   * once paid, EXCEPT on a payslip amended after payment, which is the case it exists for.
   */
  outstanding: number;
}

export type PayrollRunType = "regular" | "thirteenth_month" | "adjustment" | "final_pay";

export interface PayrollRun {
  id: number;
  cycle_start: string;
  cycle_end: string;
  frequency: string;
  /**
   * What KIND of run this is. Distinct from `frequency`, which holds an internal key for
   * everything but a regular period (`thirteenth_month`, `adjustment`, `final_pay:{id}`).
   */
  type: PayrollRunType;
  status: string;
  total_gross: number;
  total_deductions: number;
  total_net: number;
  employee_count: number;
  run_at: string | null;
  run_by: string | null;
  /** A locked run can no longer be recomputed. */
  locked_at: string | null;
  locked_by: string | null;
  is_locked: boolean;
  /** Only present when the breakdown is loaded: every payslip is disbursed AND owes nothing. */
  can_lock?: boolean;
  /**
   * An earlier period of this tax year was amended after this run computed its withholding, so it
   * is settled against year-to-date figures that have since moved. Only the runs whose tax depends
   * on the year are ever marked — a 13th month, a final pay, a correction, or the cutoff that
   * closed the year. Recomputing the run clears it.
   */
  is_tax_stale: boolean;
  tax_stale_at: string | null;
  tax_stale_reason: string | null;
  /** The rules in force when this run executed. Detail view only. */
  policy_snapshot?: PolicySnapshot | null;
  rules_drift?: RulesDriftEntry[];
  /**
   * Payslips this run left untouched because they were already disbursed. Present on
   * the response to a run that just executed; null when a run is merely read back.
   */
  skipped_disbursed?: number | null;
  breakdown?: PayrollBreakdownRow[];
}

/**
 * How the year-end tax settlement on a December (or final-pay) payslip was arrived at.
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

/** How one statutory contribution on a payslip was arrived at. */
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
 * a leftover that is no longer written and no longer prices anything. The rates
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
 * Everything that priced a payroll run. `version` guards the shape — v1 runs
 * stored a flat map of deduction fields only.
 */
export interface PolicySnapshot {
  version?: number;
  captured_at?: string;
  attendance_policy?: Record<string, unknown>;
  /** Enrolment only ("does the company operate this?") — NOT the rates. */
  contributions?: PolicySnapshotContribution[];
  /** The rates that actually priced the run. Absent on runs from before. */
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

/** WHAT A CORRECTION LEAVES ON A LINE. */
export interface PayslipLineCorrection {
  /** The engine's own figure, struck through beside the corrected one. */
  original_amount?: number | null;
  /** The line's original wording, when HR renamed it as well. */
  original_name?: string | null;
  overridden?: boolean;
  /** The correction's id — what "remove this edit" is addressed to. */
  override_id?: number | null;
  override_reason?: string | null;
  override_issued_on?: string | null;
  override_by?: string | null;
}

/** One named money line on the payslip (allowance component, benefit share, loan). */
export interface PayslipLine extends PayslipLineCorrection {
  /**
   * WHICH LINE THIS IS, in the payslip's own vocabulary — `basic`, `overtime`, `sss`,
   * `allowance:transport-allowance`. Stable across re-runs and independent of the wording, which is
   * what makes a correction to a line survive one. Absent on `allowances_breakdown` and
   * `other_deductions_breakdown`, which are raw lists rather than document lines.
   */
  key?: string;
  name: string;
  amount: number;
  /**
   * The small grey qualifier beside the label ("2.5 hrs", "Tax-exempt", "6 day(s)").
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
   * Which obligations that shortfall belongs to, keyed by column name. Empty when
   * nothing went short, and on payslips issued before the split — the migration doesn't backfill.
   */
  uncollected_breakdown: Partial<Record<PayslipDeductionKey, number>>;
}

/** One row of the Deductions table: a REASON, its dates, and its money. */
export interface PayslipDeductionRow extends PayslipLineCorrection {
  /**
   * `absent` · `undertime` · `half_day` · `late` · `suspended_unpaid` · …, or `other:<name slug>`
   * for a benefit share, a loan repayment or a deduction added by a correction.
   */
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

/** The whole month, carried by its LAST cutoff (FIN — payslip redesign). */
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
  // Every earning as a named line, built by PayslipResource so the screen and the PDF
  // render the same list. Sums to `totals.compensation` for every run type.
  earnings_breakdown: PayslipLine[];
  // Interest collected on loans this period, also itemised in other_deductions_breakdown.
  loan_interest: number;
  // Stored all along and never serialised until — a 13th-month payslip showed a gross with
  // every earnings line at zero and a tax figure with no visible basis.
  thirteenth_month: number;
  other_benefits: number;
  taxable_pay: number;
  /** The run this payslip belongs to — adding a line to it is a run-level call. */
  payroll_run_id: number;
  /** A locked period refuses every correction. Said before HR types one, not after. */
  run_is_locked: boolean;
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
  /** Recurring benefit shares (HMO, union dues) and loans. Post-tax. */
  other_deductions: number;
  other_deductions_breakdown: PayslipLine[];
  /** The Deductions table, one row per reason with the dates behind it. */
  deductions_breakdown: PayslipDeductionRow[];
  total_deductions: number;
  net_pay: number;
  /**
   * WHAT ACTUALLY LEFT THE COMPANY for this payslip, frozen when it was marked paid. Null while
   * unpaid.
   */
  disbursed_amount: number | null;
  /** `net_pay - disbursed_amount` — what is still to be paid. */
  amount_outstanding: number;
  /** When it was paid out. Null while unpaid. */
  disbursed_at: string | null;
  /** The corrections on this payslip, oldest first. Also itemised in `earnings_breakdown`. */
  amendments: PayslipAmendment[];
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
   * What each statutory contribution was charged on, keyed by type. `salary` is the
   * figure it was computed FROM, `charged_on` the figure the rate was applied TO; they differ
   * whenever a cap, floor or SSS bracket sits between them, which is precisely when a deduction
   * looks wrong. Absent for payslips predating the snapshot, and `{ overridden: true }` alone
   * when a per-employee peso amount replaced the rate.
   */
  contribution_basis?: Record<string, ContributionBasis>;
  /**
   * The year's figures behind a December settlement. Present only on the payslip that
   * closes a tax year (or a final-pay slip), null everywhere else.
   */
  tax_annualisation_basis?: TaxAnnualisationBasis | null;
  /**
   * Which statutory deductions came from a per-employee amount on the profile rather
   * than the government rates. HR-only, and empty on payslips run before the column existed.
   * An override moves the employee half only, so these lines do not pair with the employer ones.
   */
  contribution_overrides?: ("sss" | "philhealth" | "pagibig")[];
  /**
   * The monthly salary figure each contribution was actually charged on. HR-only.
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
   * The per-date ledger behind `unpaid_days` and `late_minutes`, grouped by the two
   * questions people actually ask. Null on payslips issued before the column existed, which the
   * document renders by simply not showing the note.
   */
  attendance_lines: {
    unpaid: AttendanceChargeLine[];
    late: AttendanceChargeLine[];
  } | null;
  /**
   * The rule that turned late/undertime minutes into a fraction of a day, from the
   * run's own policy snapshot. Null when nothing was charged, or on runs predating the snapshot.
   */
  deduction_rule: DeductionRule | null;
  /** How `withholding_tax` was arrived at. Null when nothing was withheld. */
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
/** What a 13th-month RUN would pay one employee — not the projection. */
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

/** Which way a correction moves money, and what it does to the tax. */
export type AmendmentKind = "pay" | "pay_reversal" | "deduction";

/**
 * One line of a correction — whose payslip, how much, and what it is for.
 *
 * `amount` is ALWAYS POSITIVE. The direction belongs to the batch's `kind`, so a deduction batch
 * cannot contain a line that quietly pays.
 */
export interface AmendmentLine {
  employee_id: number;
  amount: number;
  note?: string | null;
}

/** The three figures a preview compares, per employee. */
export interface RerunFigures {
  gross: number;
  deductions: number;
  net: number;
}

/**
 * One employee in a re-run preview: what the period says now, and what it would say.
 *
 * `skipped` is not "nothing changed" — it means the payslip was already disbursed and the re-run
 * will refuse to touch it, so `after` restates what was paid rather than what recomputing produced.
 */
export interface RerunPreviewRow {
  employee_id: number;
  employee: string | null;
  /** The stored payslip's status. Null when the employee has no payslip in the period yet. */
  status: string | null;
  change: "changed" | "added" | "removed" | "skipped" | "unchanged";
  before: RerunFigures | null;
  after: RerunFigures | null;
  /** `after.net - before.net`, and zero on a skipped or one-sided row. */
  delta: number;
}

export interface RerunPreviewMeta {
  net_before: number;
  net_after: number;
  delta: number;
  changed: number;
  added: number;
  removed: number;
  skipped: number;
  unchanged: number;
  /** The run's withholding is stale, and recomputing is what clears the mark. */
  clears_tax_stale: boolean;
}

/** One correction already standing against a payslip. */
export interface PayslipAmendment {
  id: number;
  kind: AmendmentKind;
  /** How the kind reads on a document — "Additional pay", "Pay reversed", "Deduction added". */
  kind_label: string;
  /**
   * WHICH LINE THIS CORRECTED, when it corrected one rather than adding one. Null on an added
   * line — that is the whole difference between the two, and it decides whether the correction is
   * printed ON a line or as a line of its own.
   */
  target_key: string | null;
  /** That line's name on the document, HR's wording included. Null on an added line. */
  target_label: string | null;
  /** What HR says the line is. Null on a correction to the wording alone. */
  override_amount: number | null;
  /** What the engine computed — the struck-through figure, refreshed on every run. */
  original_amount: number | null;
  /** The wording HR gave the line. Display only: the line keeps its key. */
  label_override: string | null;
  /** Signed: negative for a reversal or a deduction, positive for extra pay. */
  amount: number;
  /** Withheld on this amount, marginally on top of the year's earnings. Negative = refunded. */
  tax: number;
  /** `amount - tax` — what it added to net pay. */
  net: number;
  /** Why the batch existed. */
  reason: string;
  /** What this employee's line was for. Null when the batch reason said it all. */
  note: string | null;
  issued_on: string | null;
  issued_by: string | null;
}

/** An outstanding retroactive pay difference. */
export interface RetroFlag {
  id: number;
  employee: { id: number | null; name: string | null };
  direction: "owed" | "overpaid";
  reason: string;
  /** The settled run itself, so settling can open straight onto the period that owes it. */
  payroll_run_id: number | null;
  /** The settled cutoff the decision reached into, e.g. "2026-03-01 – 2026-03-15". */
  period: string | null;
  /** Which kind of request caused it — OvertimeRequest, LeaveRequest, … */
  source: string;
  source_id: number;
  resolved_at: string | null;
  resolution_note: string | null;
  created_at: string | null;
}

// --- Disbursement ------------------------------------------------------------------

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
  /** Masked to the last four digits. The full number is never served to a screen. */
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

/** A statutory or benefit deduction a thin payslip could not collect, still outstanding. */
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
 * The withholding-tax table, exposed so it can be checked against the BIR schedule.
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

/** One employee this run would pay below the regional minimum daily wage. */
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
  /** Periods already settled when the raise takes effect — recorded as back pay owed. */
  back_pay_flagged: number;
}

/** One attendance problem worth seeing before a cycle is priced (item 12). */
export interface AttendanceException {
  key:
    | "unpriced_days"
    | "overtime_held"
    | "approved_without_punch"
    | "long_shifts"
    | "overtime_flagged";
  label: string;
  count: number;
  blocking: boolean;
  detail: string;
  where: string;
}
