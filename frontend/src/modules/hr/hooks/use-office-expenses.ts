"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { QueueParams } from "@/modules/hr/api/queue";

import {
  getOfficeExpenses,
  getOfficeExpenseSummary,
  createOfficeExpense,
  updateOfficeExpense,
  approveOfficeExpense,
  rejectOfficeExpense,
  reopenOfficeExpense,
  deleteOfficeExpense,
} from "@/modules/hr/api/office-expenses";
import type { OfficeExpenseInput } from "@/modules/hr/types/office-expense";

const KEY = ["hr", "office-expenses"] as const;

/**
 * The spend cards above the ledger. Given the SAME params as the table below them, so the two
 * cannot end up describing different windows on the same screen.
 */
export function useOfficeExpenseSummary(params: QueueParams & { from?: string; to?: string }) {
  return useQuery({
    queryKey: [...KEY, "summary", params] as const,
    queryFn: () => getOfficeExpenseSummary(params),
    placeholderData: keepPreviousData,
  });
}

/** One page of the office-expense queue, with the total. Filtered and ordered by the database. */
export function useOfficeExpenses(params: QueueParams) {
  return useQuery({
    queryKey: [...KEY, params] as const,
    queryFn: () => getOfficeExpenses(params),
    // Turning a page swaps the rows rather than emptying the table and rebuilding it.
    placeholderData: keepPreviousData,
  });
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
