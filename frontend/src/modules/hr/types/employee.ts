export interface DepartmentRef {
  id: number;
  name: string;
  code: string;
}

export interface ManagerRef {
  id: number;
  name: string | null;
}

/**
 * Who a record belongs to, as embedded in the HR queue payloads: enough to render a
 * row's identity — the name plus the face. `photo_url` is null when they never uploaded
 * one, and absent when the endpoint didn't load the employee; both mean initials.
 */
export interface EmployeeRef {
  id: number;
  name: string | null;
  photo_url?: string | null;
}

/** One named sibling on the personal record. */
export interface Sibling {
  name: string;
  gender: string | null;
}

export interface Employee {
  id: number;
  employee_no: string;
  name: string; // composed identity from the split-name fields (users.name)
  email: string;
  is_hr_officer: boolean; // account has HR-officer (admin) access
  photo_url: string | null; // profile picture; null falls back to initials

  job_title: string;
  employment_status: string;
  work_location: string | null;
  employee_role: string | null;
  onboarding_status: "pending" | "complete" | null;
  hire_date: string;
  // MF-18 — the employment lifecycle. `separation_date` is the last WORKING day (what payroll
  // prorates against and what final pay requires); `separated_at` is the archive stamp, kept in sync
  // with it. `previous_hire_date` is set only by a rehire, so restarting tenure doesn't erase when
  // this person first joined.
  separation_type: "voluntary" | "involuntary" | null;
  separation_date: string | null;
  separated_at: string | null;
  is_separated: boolean;
  previous_hire_date: string | null;
  base_salary: string; // raw decimal from the API; format for display
  is_management: boolean; // fixed salary + exempt from attendance
  phone: string | null;
  address: string | null; // home address
  emergency_contact_name: string | null;
  emergency_contact_relationship: string | null;
  emergency_contact_phone: string | null;
  bank_name: string | null;
  bank_account: string | null; // account number
  pay_frequency: string | null;
  /**
   * FIN-030 — the DERIVED daily rate against the configured regional floor. Null when no floor is
   * configured, in which case there is nothing to warn about. Advisory only: the floor varies by
   * region, industry and establishment size, so this informs rather than blocks.
   */
  minimum_wage_check: { daily_rate: number; minimum: number; below: boolean } | null;
  currency: string | null;
  bank_account_type: string | null;
  bank_account_name: string | null;
  sss_contribution: string | null;
  pagibig_contribution: string | null;
  philhealth_contribution: string | null;
  // Government membership NUMBERS for the payslip header — not the peso overrides above.
  tin: string | null;
  sss_number: string | null;
  philhealth_number: string | null;
  pagibig_number: string | null;
  department: DepartmentRef | null;
  manager: ManagerRef | null;
  created_at: string;
  // Expanded personal record.
  first_name: string | null;
  middle_name: string | null;
  last_name: string | null;
  name_extension: string | null;
  gender: string | null;
  civil_status: string | null;
  nationality: string | null;
  date_of_birth: string | null;
  place_of_birth: string | null;
  current_address: string | null;
  mothers_maiden_name: string | null;
  fathers_name: string | null;
  siblings: Sibling[];
  /** Total siblings — may exceed the names listed above. */
  number_of_siblings: number | null;
  high_school: string | null;
  college_course: string | null;
  university: string | null;
  height: string | null;
  weight: string | null;
  health_condition: string | null;
  employment_history: EmploymentHistoryEntry[];
}

export interface EmploymentHistoryEntry {
  id: number;
  title: string;
  start_date: string;
  end_date: string | null; // null = current
  status: string; // current | promoted | transferred | hired
}

export interface EmployeeInput {
  first_name: string;
  last_name: string;
  middle_name?: string;
  name_extension?: string;
  email: string;
  department_id: number;
  manager_id?: number | null;
  job_title: string;
  employment_status: string;
  hire_date: string;
  base_salary: number;
  is_management?: boolean; // fixed salary + exempt from attendance
  phone?: string;
  address?: string;
  current_address?: string;
  gender?: string;
  civil_status?: string;
  nationality?: string;
  date_of_birth?: string;
  place_of_birth?: string;
  mothers_maiden_name?: string;
  fathers_name?: string;
  siblings?: Sibling[];
  number_of_siblings?: number | null;
  high_school?: string;
  college_course?: string;
  university?: string;
  emergency_contact_name?: string;
  emergency_contact_relationship?: string;
  emergency_contact_phone?: string;
  bank_name?: string;
  bank_account?: string;
  height?: string;
  weight?: string;
  health_condition?: string;
  work_location?: string;
  employee_role?: string;
  pay_frequency?: string;
  currency?: string;
  bank_account_type?: string;
  bank_account_name?: string;
  sss_contribution?: string;
  pagibig_contribution?: string;
  philhealth_contribution?: string;
  tin?: string;
  sss_number?: string;
  philhealth_number?: string;
  pagibig_number?: string;
  // Audit inputs (only meaningful on update when the value actually changes).
  salary_change_reason?: string;
  salary_change_type?: string;
  /** FIN-007 — when the new salary takes effect. Null/omitted means today. */
  salary_effective_date?: string | null;
  /**
   * FIN-007 — HR has been shown which completed payroll runs this date reaches into and wants to
   * record it anyway. The backend refuses without it and names the periods.
   */
  acknowledge_repricing?: boolean;
  role_change_type?: string;
  role_change_reason?: string;
}

/**
 * An edit to an existing record. The API applies only the keys it is sent, so each
 * section of the employee record saves its own fields without disturbing the rest.
 * An explicit `null` clears a field — omitting the key leaves it as it was.
 */
export type EmployeeUpdateInput = {
  [K in keyof EmployeeInput]?: EmployeeInput[K] | null;
};

export const GENDERS = ["Male", "Female", "Other", "Prefer not to say"] as const;
export const CIVIL_STATUSES = ["Single", "Married", "Widowed", "Separated", "Divorced"] as const;
export const PAY_FREQUENCIES = [
  { value: "monthly", label: "Monthly" },
  { value: "semi_monthly", label: "Semi-monthly" },
  { value: "weekly", label: "Weekly" },
  { value: "bi_weekly", label: "Bi-weekly" },
] as const;

export const BANK_ACCOUNT_TYPES = ["Savings", "Checking", "Payroll"] as const;

export const SALARY_CHANGE_TYPES = [
  { value: "promotion", label: "Promotion" },
  { value: "merit", label: "Merit increase" },
  { value: "adjustment", label: "Adjustment" },
] as const;

export function payFrequencyLabel(v: string | null): string {
  return PAY_FREQUENCIES.find((f) => f.value === v)?.label ?? "—";
}

/** Short badge label for a compensation-history entry, e.g. "Promotion" / "Hire". */
export function salaryChangeLabel(t: string | null): string {
  if (!t) return "Adjustment";
  return t.charAt(0).toUpperCase() + t.slice(1);
}

export interface EmployeeDocument {
  id: number;
  name: string;
  size_bytes: number;
  label: string; // "Uploaded" | "Signed"
  url: string | null;
  created_at: string;
}

// Employee self-service view of an HR-uploaded document: listed always, but the
// download URL is gated behind a fulfilled access request.
export interface MyDocument {
  id: number;
  name: string;
  label: string;
  size_bytes: number;
  created_at: string;
  request_status: "none" | "pending" | "in_progress" | "fulfilled" | "rejected";
  unlocked: boolean;
  url: string | null;
}

export interface SalaryHistoryEntry {
  id: number;
  base_salary: number;
  effective_date: string;
  reason: string | null;
  change_type: string | null; // hire | promotion | merit | adjustment
  changed_by: string | null;
}

// Mirrors the backend EmployeeService::STATUSES. This is the single employment
// classification on the record — leave allotments and benefits eligibility are
// keyed on it, and the Job tab shows it.
export const EMPLOYMENT_STATUSES = [
  "regular",
  "probationary",
  "contract",
  "part_time",
  "project_based",
  "ojt",
] as const;

const STATUS_LABELS: Record<string, string> = {
  regular: "Regular",
  probationary: "Probationary",
  contract: "Contract",
  part_time: "Part-time",
  project_based: "Project-based",
  ojt: "Internship/OJT",
};

/** Display label for an employment_status, e.g. part_time -> "Part-time". */
export function statusLabel(status: string): string {
  return (
    STATUS_LABELS[status] ??
    status
      .split("_")
      .map((w) => w[0]?.toUpperCase() + w.slice(1))
      .join(" ")
  );
}

/**
 * One statutory contribution for an employee: what the government rates would charge, and the
 * per-employee override (if any) that is replacing it (Slice B7).
 *
 * `calculated_employer` is here because it does NOT move with the override — the mismatch between
 * the two halves is the whole reason this is shown.
 */
export interface StatutoryPreviewRow {
  type: "sss" | "philhealth" | "pagibig";
  /** False ⇒ nothing is deducted at all and an override on it is inert. */
  enrolled: boolean;
  override: number | null;
  calculated_employee: number;
  calculated_employer: number;
  basis: string | null;
  /** Slice B19 — which salary figure this is charged on: `basic` or `earnings`. */
  salary_basis: string | null;
  /**
   * True when `salary_basis` is `earnings`, in which case the two figures above are a PERFECT
   * MONTH — no absences, no lates, no overtime — because this preview has only the profile salary
   * to work from. The real payslip will rarely match, and the form has to say so.
   */
  is_estimate: boolean;
}
