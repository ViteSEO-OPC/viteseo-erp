import { apiClient } from "@/core/api-client";
import type {
  ContributionSetting,
  EmployeeBenefit,
} from "@/modules/hr/types/benefits";

export async function getContributionSettings(): Promise<ContributionSetting[]> {
  const res = await apiClient.get("/hr/contribution-settings");
  return res.data.data;
}

/**
 * Whether the company operates this contribution at all — NOT its rates (those are dated
 * versions, see `saveContributionVersion`). removed the free-form `config`
 * write: it edited a field payroll stopped pricing from in B3a.
 */
export async function updateContributionSetting(
  id: number,
  input: { is_active: boolean },
): Promise<ContributionSetting> {
  const res = await apiClient.put(`/hr/contribution-settings/${id}`, input);
  return res.data.data;
}

export async function getEmployeeBenefits(
  employeeId: number,
): Promise<EmployeeBenefit[]> {
  const res = await apiClient.get(`/hr/employees/${employeeId}/benefits`);
  return res.data.data;
}

/**
 * Which government contributions the CALLER pays into, read-only. Identity from the token.
 *
 * Named for contributions, not "benefits": `api/benefit-programs.ts` already has a `getMyBenefits`
 * for the caller's allowance PROGRAMMES, and the two return different shapes.
 */
export async function getMyContributions(): Promise<EmployeeBenefit[]> {
  const res = await apiClient.get("/hr/me/benefits");
  return res.data.data;
}

export async function updateEmployeeBenefits(
  employeeId: number,
  benefits: { contribution_type: string; enrolled: boolean }[],
): Promise<EmployeeBenefit[]> {
  const res = await apiClient.put(`/hr/employees/${employeeId}/benefits`, {
    benefits,
  });
  return res.data.data;
}

// --- Dated statutory rate versions -----------------------------------

/**
 * One version of a government contribution's rates, in force from `effective_from` until a
 * later version supersedes it. Rates are DECIMALS here (0.05 = 5%) — the dialog converts.
 */
export interface ContributionVersion {
  id: number;
  type: string;
  effective_from: string;
  /** HOW the charge is worked out: `percentage` of the salary, or the `bracket` table's MSC. */
  basis: string;
  /**
   * WHICH salary figure goes in, which is a different question from `basis` above:
   * `basic` (the profile's monthly rate) or `earnings` (what the period actually paid).
   */
  salary_basis: string;
  employee_rate: number;
  employer_rate: number;
  salary_floor: number | null;
  salary_cap: number | null;
  employee_amount_cap: number | null;
  employer_amount_cap: number | null;
  fixed_employer_add_on: number;
  add_on_min_salary: number | null;
  note: string | null;
  created_by: string | null;
  /** MSC bands, lowest first. Each runs up to the next band's start. */
  brackets: ContributionBand[];
}

/**
 * One MSC band. `employer_add_on` is that band's employer-only EC amount as the agency prints it
 * Null falls back to the version's flat add-on rule.
 */
export interface ContributionBand {
  min_salary: number;
  msc: number;
  employer_add_on?: number | null;
}

export type ContributionVersionInput = Omit<
  ContributionVersion,
  "id" | "created_by" | "basis" | "brackets"
> & {
  basis?: string;
  brackets?: ContributionBand[];
  /**
   * HR has been shown which completed payroll runs this date reaches into, and wants to
   * save anyway. Omitted on a first attempt: the backend refuses and names the affected runs, and
   * the UI turns that into a confirmation rather than a dead end.
   */
  acknowledge_repricing?: boolean;
};

export async function getContributionVersions(type?: string): Promise<ContributionVersion[]> {
  const res = await apiClient.get("/hr/contribution-versions", {
    params: type ? { type } : undefined,
  });
  return res.data.data;
}

export async function saveContributionVersion(
  input: ContributionVersionInput,
): Promise<{ id: number }> {
  const res = await apiClient.post("/hr/contribution-versions", input);
  return res.data.data;
}

/**
 * What the importer made of an uploaded schedule. A DRY RUN — nothing is saved until
 * HR confirms the reading and the version is created in the usual way.
 */
export interface ScheduleImportResult {
  /** 1-indexed row the table's headings were found on, or null if they were not found. */
  header_row: number | null;
  /** Which spreadsheet column was used for what, e.g. `{ msc: "D" }`. */
  columns: Partial<Record<"range" | "msc" | "employer_add_on" | "employee_total" | "employer_total", string>>;
  bands: {
    min_salary: number;
    msc: number;
    employer_add_on: number | null;
    /** The agency's own printed amounts, used to check our arithmetic. */
    published_employee: number | null;
    published_employer: number | null;
    /** The range exactly as the file prints it, e.g. "19,750 - 20,249.99". */
    range: string;
  }[];
  suggested: {
    basis?: string;
    employee_rate?: number;
    employer_rate?: number;
    salary_cap?: number;
    band_count?: number;
    /** False ⇒ rate × MSC does not reproduce the printed amounts. Check before saving. */
    amounts_match?: boolean;
  };
  warnings: string[];
  /** The circular line above the table, which usually names the effective date. */
  title: string | null;
}

/** Upload a published schedule and see what the system makes of it. Saves nothing. */
export async function importContributionSchedule(
  file: File,
  columns?: Record<string, string>,
): Promise<ScheduleImportResult> {
  const body = new FormData();
  body.append("file", file);
  Object.entries(columns ?? {}).forEach(([field, column]) => {
    if (column) body.append(`columns[${field}]`, column);
  });

  const res = await apiClient.post("/hr/contribution-versions/import-schedule", body);
  return res.data.data;
}

/** Remove a version saved against the wrong date. The last one for a type cannot be removed. */
export async function deleteContributionVersion(id: number): Promise<void> {
  await apiClient.delete(`/hr/contribution-versions/${id}`);
}
