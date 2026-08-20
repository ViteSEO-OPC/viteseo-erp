"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getBenefitPrograms,
  createBenefitProgram,
  createBenefitPlan,
  updateBenefitProgram,
  patchBenefitProgram,
  deleteBenefitProgram,
  getMyBenefits,
  getEmployeeAllowances,
  setEmployeeAllowance,
  getPlanEnrollments,
  updatePlanEnrollments,
  endPlanEnrollment,
} from "@/modules/hr/api/benefit-programs";
import type { BenefitProgramInput } from "@/modules/hr/types/benefits";

const PROGRAMS_KEY = ["hr", "benefit-programs"] as const;
const MY_KEY = ["hr", "me", "benefits"] as const;

export function useBenefitPrograms() {
  return useQuery({ queryKey: PROGRAMS_KEY, queryFn: getBenefitPrograms });
}

export function useCreateBenefitProgram() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: BenefitProgramInput) => createBenefitProgram(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: PROGRAMS_KEY }),
  });
}

export function useUpdateBenefitProgram() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: BenefitProgramInput }) =>
      updateBenefitProgram(id, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: PROGRAMS_KEY }),
  });
}

export function useDeleteBenefitProgram() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteBenefitProgram(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: PROGRAMS_KEY }),
  });
}

// Partial update (Edit Allowance / Edit HMO modals).
export function usePatchBenefitProgram() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, patch }: { id: number; patch: Record<string, unknown> }) =>
      patchBenefitProgram(id, patch),
    onSuccess: () => qc.invalidateQueries({ queryKey: PROGRAMS_KEY }),
  });
}

// Create a plan inline (e.g. a new allowance from the Assign modal).
export function useCreateBenefitPlan() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: Record<string, unknown>) => createBenefitPlan(payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: PROGRAMS_KEY }),
  });
}

export function useMyBenefits() {
  return useQuery({ queryKey: MY_KEY, queryFn: getMyBenefits });
}

// ---- Company-plan enrolment ----

const planEnrollmentsKey = (programId: number) =>
  ["hr", "benefit-programs", programId, "enrollments"] as const;

export function usePlanEnrollments(programId: number | null) {
  return useQuery({
    queryKey: planEnrollmentsKey(programId ?? 0),
    queryFn: () => getPlanEnrollments(programId as number),
    enabled: programId != null,
  });
}

export function useUpdatePlanEnrollments(programId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (
      enrollments: {
        employee_id: number;
        tier?: string | null;
        employee_share?: number | null;
        enrolled_at?: string | null;
        ended_at?: string | null;
      }[],
    ) => updatePlanEnrollments(programId, enrollments),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: planEnrollmentsKey(programId) });
      qc.invalidateQueries({ queryKey: PROGRAMS_KEY });
    },
  });
}

export function useEndPlanEnrollment(programId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (employeeId: number) => endPlanEnrollment(programId, employeeId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: planEnrollmentsKey(programId) });
      qc.invalidateQueries({ queryKey: PROGRAMS_KEY });
    },
  });
}

// ---- One employee's own allowance amounts ----

const employeeAllowancesKey = (employeeId: number) =>
  ["hr", "employees", employeeId, "allowances"] as const;

export function useEmployeeAllowances(employeeId: number) {
  return useQuery({
    queryKey: employeeAllowancesKey(employeeId),
    queryFn: () => getEmployeeAllowances(employeeId),
    enabled: Number.isFinite(employeeId),
  });
}

export function useSetEmployeeAllowance(employeeId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ programId, amount }: { programId: number; amount: number | null }) =>
      setEmployeeAllowance(employeeId, programId, amount),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: employeeAllowancesKey(employeeId) });
      // The catalogue carries following/overridden counts, so the edit dialog would otherwise
      // keep promising an edit reaches somebody it no longer reaches.
      qc.invalidateQueries({ queryKey: PROGRAMS_KEY });
    },
  });
}

