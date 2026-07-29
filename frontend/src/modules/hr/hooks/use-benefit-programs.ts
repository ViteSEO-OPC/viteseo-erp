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

