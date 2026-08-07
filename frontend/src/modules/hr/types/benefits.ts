// Company → Benefits (Phase-2 Slice 13).

/** The rate version actually in force today — what payroll charges (Slice B3a/b). */
export interface CurrentContributionRates {
  effective_from: string;
  basis: string;
  employee_rate: number;
  employer_rate: number;
  salary_floor: number | null;
  salary_cap: number | null;
  employee_amount_cap: number | null;
  fixed_employer_add_on: number;
  bracket_count: number;
}

export interface ContributionSetting {
  id: number;
  type: string; // sss | philhealth | pagibig
  config: Record<string, number | string>;
  is_active: boolean;
  /**
   * Read rates from HERE, not from `config` — `config` is the legacy row payroll no longer
   * uses, and the two drifted apart (the card showed 4.5% while payroll charged 5%).
   */
  current: CurrentContributionRates | null;
}

export interface EmployeeBenefit {
  id: number;
  contribution_type: string;
  enrolled: boolean;
}

/** Human labels for the known contribution types. */
export const CONTRIBUTION_LABELS: Record<string, string> = {
  sss: "SSS",
  philhealth: "PhilHealth",
  pagibig: "Pag-IBIG",
};

// ---- Benefit programs (flexible catalogue, HR-authored) ----

export interface BenefitDetail {
  label: string;
  value: string;
}

/** A coverage tier on an HMO / company plan. */
export interface BenefitTier {
  name: string;
  monthly_premium: number;
  employee_share: number;
}

export const ALLOWANCE_FREQUENCIES = [
  { value: "monthly", label: "Monthly" },
  { value: "semi_monthly", label: "Semi-monthly" },
  { value: "annual", label: "Annual" },
] as const;

export const TAX_TREATMENTS = [
  { value: "de_minimis", label: "De minimis · non-taxable" },
  { value: "taxable", label: "Taxable" },
] as const;

/**
 * Slice B19 — whether an allowance is remuneration for SSS / Pag-IBIG purposes.
 *
 * A SEPARATE QUESTION FROM TAX, which is why it is not folded into TAX_TREATMENTS above: SSS
 * expressly includes the mandated COLA, which is de-minimis for tax, and a taxable allowance can
 * legitimately sit outside the contribution base. One combined switch would force a wrong answer
 * on one of the two.
 */
/**
 * Slice B19 — which salary figure a contribution's rate is applied to.
 *
 * Not the same axis as percentage-vs-bracket, which is how the charge is worked out once a figure
 * is chosen. The two were both called "basis" in the schema and keeping them apart in the UI is
 * the point of the separate labels here.
 */
export const SALARY_BASES = [
  { value: "basic", label: "Basic monthly salary" },
  { value: "earnings", label: "Actual earnings for the period" },
] as const;

export const CONTRIBUTION_TREATMENTS = [
  { value: "yes", label: "Counts toward contributions" },
  { value: "no", label: "Excluded · still paid in full" },
] as const;

/**
 * How an allowance decides who it covers. The first three are RULES, evaluated when payroll runs,
 * so a promotion or a new hire is followed automatically; `custom` is a stored list of names.
 */
export const ELIGIBILITY_MODES = [
  { value: "all", label: "All employees" },
  { value: "status", label: "By employment status" },
  { value: "management", label: "Management only" },
  { value: "custom", label: "Specific employees" },
] as const;

export type EligibilityMode = (typeof ELIGIBILITY_MODES)[number]["value"];

export const HMO_CADENCES = [
  { value: "per_cutoff", label: "Per cutoff" },
  { value: "monthly", label: "Monthly" },
] as const;

export function frequencyLabel(v: string): string {
  return ALLOWANCE_FREQUENCIES.find((f) => f.value === v)?.label ?? v;
}

export interface BenefitProgress {
  percent: number;
  caption?: string | null;
}

/** HR management view — includes the enrolled employee ids. */
export interface BenefitProgram {
  id: number;
  name: string;
  category: string;
  /** What it does to a payslip: adds to gross | deducts per period | the product a loan is issued under. */
  kind: "allowance" | "plan" | "loan_policy";
  amount: string | null; // allowance peso / month (raw decimal string)
  description: string | null; // company-benefit blurb
  color: string | null; // card dot colour
  // Allowance config.
  frequency: string; // monthly | semi_monthly | annual
  tax_treatment: string; // de_minimis | taxable
  /** This benefit's OWN statutory ceiling (B16). Null = none recorded ⇒ fully tax-free. */
  deminimis_ceiling: string | null;
  /** The period the ceiling is stated in: monthly | semester | annual. */
  ceiling_period: string | null;
  /**
   * Slice B19 — does this allowance count toward the SSS / Pag-IBIG contribution base? It is paid
   * in full either way; this decides only whether it is remuneration for contribution purposes.
   * Independent of `tax_treatment`, and inert until a contribution is moved onto the earnings
   * basis in Company → Benefits.
   */
  contributable: boolean;
  // Loan-policy config (B15), only meaningful when kind is "loan_policy".
  loan_max_principal: string | null;
  loan_term_periods: number | null;
  eligibility: string; // all | status | management | custom
  /** Only meaningful when `eligibility` is "status". */
  eligibility_statuses: string[];
  /** How many employees the rule covers right now — computed, not stored. */
  covered_count: number;
  /**
   * Of those, how many an edit to `amount` would actually reach (B14). `overridden` are the
   * people carrying their own amount, who will not move.
   */
  reach: { following: number; overridden: number };
  // HMO / company-plan config.
  deduction_cadence: string | null; // per_cutoff | monthly
  tiers: BenefitTier[];
  status: string;
  details: BenefitDetail[];
  progress: BenefitProgress | null;
  footer_label: string | null;
  employee_ids: number[];
}

/** One person's enrolment on a company plan (B2). */
export interface PlanEnrollment {
  employee_id: number;
  name: string;
  tier: string | null;
  /** What is deducted from their pay. Null/0 ⇒ the company pays the whole premium. */
  employee_share: string | null;
  enrolled_at: string | null;
  /** Set ⇒ they have left the plan; nothing is deducted after this date. */
  ended_at: string | null;
}

/** The plan-enrolment screen's payload: who is on it, and who could be added. */
export interface PlanEnrollments {
  data: {
    enrolled: PlanEnrollment[];
    eligible: { employee_id: number; name: string }[];
  };
  meta: {
    tiers: BenefitTier[];
    deduction_cadence: string | null;
    /** "Deducts every cutoff" + "nobody has a share" = deducts nothing. Almost never intended. */
    deducts_but_no_shares: boolean;
  };
  /** Rows the server refused — ineligible employee, unknown tier. Never silently dropped. */
  rejected?: string[];
}

/** One allowance as it applies to ONE employee (B14) — the employee-profile view. */
export interface EmployeeAllowance {
  program_id: number;
  name: string;
  frequency: string;
  tax_treatment: string;
  color: string | null;
  /** What the programme pays everybody. */
  program_amount: number;
  /** This employee's own amount, or null when they follow the programme. */
  amount: number | null;
  set_by: string | null;
  set_at: string | null;
}

/** Employee "My Benefits" view — their own benefits and what each is worth (B12). */
export interface MyBenefit {
  id: number;
  name: string;
  category: string;
  kind: "allowance" | "plan" | "loan_policy";
  color: string | null;
  status: string;
  /** Allowances only: what THIS employee is paid per month. Never the company standard. */
  monthly_amount: number | null;
  /** Company plans only: what is deducted from their pay. Null ⇒ the company pays it all. */
  employee_share: string | null;
  tier: string | null;
  /** Allowances only — it changes their withholding, so it is theirs to see. */
  tax_treatment: string | null;
  description: string | null;
  details: BenefitDetail[];
  progress: BenefitProgress | null;
  footer_label: string | null;
}

/** Fields HR can create/update. */
export interface BenefitProgramInput {
  name: string;
  category: string;
  status: string;
  details: BenefitDetail[];
  progress: BenefitProgress | null;
  footer_label: string | null;
  // No `is_active` — deleting is the only way to switch a programme off (B13 item 4), so that it
  // is always reversible from the same screen that did it.
  employee_ids: number[];
}

/** The category picker options (drives the card icon). */
export const BENEFIT_CATEGORIES: { value: string; label: string }[] = [
  { value: "hmo", label: "Health / HMO" },
  { value: "retirement", label: "Retirement" },
  { value: "allowance", label: "Allowance / Stipend" },
  { value: "insurance", label: "Insurance" },
  { value: "enrollment", label: "Enrollment window" },
  { value: "general", label: "Other" },
];

