"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getOfficeExpenses,
  createOfficeExpense,
  updateOfficeExpense,
  approveOfficeExpense,
  rejectOfficeExpense,
  reopenOfficeExpense,
  deleteOfficeExpense,
} from "@/modules/hr/api/office-expenses";
import type { OfficeExpenseInput } from "@/modules/hr/types/office-expense";

const KEY = ["hr", "office-expenses"] as const;

export function useOfficeExpenses() {
  return useQuery({ queryKey: KEY, queryFn: getOfficeExpenses });
}

export function useCreateOfficeExpense() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ input, receipt }: { input: OfficeExpenseInput; receipt?: File | null }) =>
      createOfficeExpense(input, receipt),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateOfficeExpense() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input, receipt }: { id: number; input: OfficeExpenseInput; receipt?: File | null }) =>
      updateOfficeExpense(id, input, receipt),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useApproveOfficeExpense() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => approveOfficeExpense(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useRejectOfficeExpense() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => rejectOfficeExpense(id, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useReopenOfficeExpense() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) => reopenOfficeExpense(id, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useDeleteOfficeExpense() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteOfficeExpense(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
