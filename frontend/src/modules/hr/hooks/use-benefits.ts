"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getContributionSettings,
  updateContributionSetting,
  getEmployeeBenefits,
  getMyContributions,
  updateEmployeeBenefits,
  getContributionVersions,
  saveContributionVersion,
  importContributionSchedule,
  deleteContributionVersion,
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
      input: { is_active: boolean };
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

/**
 * The caller's own contribution enrolment (self-service, read-only).
 *
 * DISTINCT KEY from `use-benefit-programs`'s `useMyBenefits`, which already owns
 * ["hr","me","benefits"] for a different payload. Two hooks sharing a key serve each other's data.
 */
export function useMyContributions(enabled = true) {
  return useQuery({
    queryKey: ["hr", "me", "contribution-enrolment"],
    queryFn: getMyContributions,
    enabled,
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

/** The dated rate history for one contribution. */
export function useContributionVersions(type?: string) {
  return useQuery({
    queryKey: [...VERSIONS_KEY, type ?? "all"] as const,
    queryFn: () => getContributionVersions(type),
    enabled: !!type,
  });
}

/**
 * Read an uploaded schedule. A mutation rather than a query because it takes a file and
 * is a deliberate action — and because caching "what that spreadsheet said" would be meaningless.
 */
export function useImportContributionSchedule() {
  return useMutation({
    mutationFn: ({ file, columns }: { file: File; columns?: Record<string, string> }) =>
      importContributionSchedule(file, columns),
  });
}

export function useDeleteContributionVersion() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteContributionVersion(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: VERSIONS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "contribution-settings"] });
    },
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
