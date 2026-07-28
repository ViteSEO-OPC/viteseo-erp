import { apiClient } from "@/core/api-client";
import type {
  ContributionSetting,
  EmployeeBenefit,
} from "@/modules/hr/types/benefits";

export async function getContributionSettings(): Promise<ContributionSetting[]> {
  const res = await apiClient.get("/hr/contribution-settings");
  return res.data.data;
}

export async function updateContributionSetting(
  id: number,
  input: { config?: Record<string, number | string>; is_active?: boolean },
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

export async function updateEmployeeBenefits(
  employeeId: number,
  benefits: { contribution_type: string; enrolled: boolean }[],
): Promise<EmployeeBenefit[]> {
  const res = await apiClient.put(`/hr/employees/${employeeId}/benefits`, {
    benefits,
  });
  return res.data.data;
}

// --- Dated statutory rate versions (Slice B3a) -----------------------------------

/**
 * One version of a government contribution's rates, in force from `effective_from` until a
 * later version supersedes it. Rates are DECIMALS here (0.05 = 5%) — the dialog converts.
 */
export interface ContributionVersion {
  id: number;
  type: string;
  effective_from: string;
  basis: string;
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
  /** Slice B3b — MSC bands, lowest first. Each runs up to the next band's start. */
  brackets: { min_salary: number; msc: number }[];
}

export type ContributionVersionInput = Omit<
  ContributionVersion,
  "id" | "created_by" | "basis" | "brackets"
> & {
  basis?: string;
  brackets?: { min_salary: number; msc: number }[];
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
