"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getWorkSuspensions,
  declareWorkSuspension,
  updateWorkSuspension,
  deleteWorkSuspension,
} from "@/modules/hr/api/work-suspensions";
import type { WorkSuspensionInput } from "@/modules/hr/types/holiday";

const KEY = ["hr", "work-suspensions"] as const;

/**
 * Declaring a day non-working changes what three other screens say about it — the roster's
 * statuses, the violation flags on it, and the employee's own time clock. None of them read
 * this list, so each has to be invalidated by hand.
 */
function invalidateDependents(qc: ReturnType<typeof useQueryClient>) {
  qc.invalidateQueries({ queryKey: KEY });
  qc.invalidateQueries({ queryKey: ["hr", "attendance"] });
  qc.invalidateQueries({ queryKey: ["hr", "violations"] });
  qc.invalidateQueries({ queryKey: ["hr", "me", "attendance"] });
}

export function useWorkSuspensions() {
  return useQuery({ queryKey: KEY, queryFn: getWorkSuspensions });
}

export function useDeclareWorkSuspension() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: WorkSuspensionInput) => declareWorkSuspension(input),
    onSuccess: () => invalidateDependents(qc),
  });
}

export function useUpdateWorkSuspension() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      ...input
    }: { id: number } & Partial<Omit<WorkSuspensionInput, "date">>) =>
      updateWorkSuspension(id, input),
    onSuccess: () => invalidateDependents(qc),
  });
}

export function useDeleteWorkSuspension() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteWorkSuspension(id),
    onSuccess: () => invalidateDependents(qc),
  });
}
