"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getContributionSettings,
  updateContributionSetting,
  getEmployeeBenefits,
  updateEmployeeBenefits,
  getContributionVersions,
  saveContributionVersion,
  type ContributionVersionInput,
} from "@/modules/hr/api/benefits";

const SETTINGS_KEY = ["hr", "benefits", "settings"] as const;
const benefitsKey = (employeeId: number) =>
  ["hr", "benefits", "employee", employeeId] as const;

export function useContributionSettings() {
  return useQuery({ queryKey: SETTINGS_KEY, queryFn: getContributionSettings });
}

export function useUpdateContributionSetting() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      input,
    }: {
      id: number;
      input: { config?: Record<string, number | string>; is_active?: boolean };
    }) => updateContributionSetting(id, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: SETTINGS_KEY }),
  });
}

export function useEmployeeBenefits(employeeId: number | null) {
  return useQuery({
    queryKey: employeeId ? benefitsKey(employeeId) : ["hr", "benefits", "employee", "none"],
    queryFn: () => getEmployeeBenefits(employeeId as number),
    enabled: employeeId != null,
  });
}

export function useUpdateEmployeeBenefits(employeeId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (benefits: { contribution_type: string; enrolled: boolean }[]) =>
      updateEmployeeBenefits(employeeId, benefits),
    onSuccess: () => qc.invalidateQueries({ queryKey: benefitsKey(employeeId) }),
  });
}

const VERSIONS_KEY = ["hr", "contribution-versions"] as const;

/** The dated rate history for one contribution (Slice B3a). */
export function useContributionVersions(type?: string) {
  return useQuery({
    queryKey: [...VERSIONS_KEY, type ?? "all"] as const,
    queryFn: () => getContributionVersions(type),
    enabled: !!type,
  });
}

export function useSaveContributionVersion() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: ContributionVersionInput) => saveContributionVersion(input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: VERSIONS_KEY });
      // The card shows the current rate, which may now resolve to a different version.
      qc.invalidateQueries({ queryKey: ["hr", "contribution-settings"] });
    },
  });
}
