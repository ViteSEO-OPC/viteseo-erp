"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getReimbursements,
  getReimbursementSummary,
  createReimbursement,
  approveReimbursement,
  rejectReimbursement,
  markReimbursementPaid,
  reopenReimbursement,
  cancelReimbursement,
} from "@/modules/hr/api/reimbursements";

import type { QueueParams } from "@/modules/hr/api/queue";

const KEY = ["hr", "reimbursements"] as const;
const NOTIF_KEY = ["hr", "me", "notifications"] as const;

/**
 * One page of the claims queue, with the total. Search, status and paging are applied by the
 * database — see the PagesQueues trait for why.
 */
export function useReimbursements(params: QueueParams) {
  return useQuery({
    queryKey: [...KEY, params] as const,
    queryFn: () => getReimbursements(params),
    // Turning a page swaps the rows rather than emptying the table and rebuilding it.
    placeholderData: keepPreviousData,
  });
}

/** The figures above the queue. Counted server-side — see ReimbursementSummary. */
export function useReimbursementSummary() {
  return useQuery({ queryKey: [...KEY, "summary"] as const, queryFn: getReimbursementSummary });
}

export function useCreateReimbursement() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (form: FormData) => createReimbursement(form),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: NOTIF_KEY });
    },
  });
}

// Approve / mark-paid refresh the claims list + notifications (badge updates live).
function useClaimAction(fn: (id: number) => Promise<unknown>) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => fn(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: NOTIF_KEY });
    },
  });
}

export function useApproveReimbursement() {
  return useClaimAction(approveReimbursement);
}
export function useMarkReimbursementPaid() {
  return useClaimAction(markReimbursementPaid);
}

// Reject carries a required reason.
export function useRejectReimbursement() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      rejectReimbursement(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: NOTIF_KEY });
    },
  });
}

// The employee withdrawing their own pending claim. Reason required.
export function useCancelReimbursement() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      cancelReimbursement(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: NOTIF_KEY });
    },
  });
}

// Un-paying a claim can put it back on an open payroll run, so refresh payroll too.
export function useReopenReimbursement() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) =>
      reopenReimbursement(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: NOTIF_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "payroll"] });
    },
  });
}
