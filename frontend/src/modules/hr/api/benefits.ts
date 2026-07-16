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
