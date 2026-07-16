// Company → Benefits (Phase-2 Slice 13).

export interface ContributionSetting {
  id: number;
  type: string; // sss | philhealth | pagibig
  config: Record<string, number | string>;
  is_active: boolean;
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

/** Payload to assign allowances / an HMO plan to a set of employees. */
export interface AssignBenefitsInput {
  employee_ids: number[];
  allowance_ids?: number[];
  hmo?: { program_id: number; tiers: Record<number, string> };
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

export const ELIGIBILITY_OPTIONS = [
  { value: "all", label: "All employees" },
  { value: "regular", label: "Regular only" },
  { value: "custom", label: "Custom" },
] as const;

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
  eligibility: string; // all | regular | custom
  // HMO / company-plan config.
  deduction_cadence: string | null; // per_cutoff | monthly
  tiers: BenefitTier[];
  status: string;
  details: BenefitDetail[];
  progress: BenefitProgress | null;
  footer_label: string | null;
  is_active: boolean;
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
  is_active?: boolean;
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
