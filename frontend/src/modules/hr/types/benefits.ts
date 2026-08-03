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
  kind: "allowance" | "company"; // which section on the Benefits page
  amount: string | null; // allowance peso / month (raw decimal string)
  description: string | null; // company-benefit blurb
  color: string | null; // card dot colour
  // Allowance config.
  frequency: string; // monthly | semi_monthly | annual
  tax_treatment: string; // de_minimis | taxable
  eligibility: string; // all | status | management | custom
  /** Only meaningful when `eligibility` is "status". */
  eligibility_statuses: string[];
  /** How many employees the rule covers right now — computed, not stored. */
  covered_count: number;
  // HMO / company-plan config.
  deduction_cadence: string | null; // per_cutoff | monthly
  tiers: BenefitTier[];
  status: string;
  details: BenefitDetail[];
  progress: BenefitProgress | null;
  footer_label: string | null;
  employee_ids: number[];
}

/** Employee "My Benefits" view — card content only. */
export interface MyBenefit {
  id: number;
  name: string;
  category: string;
  status: string;
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

