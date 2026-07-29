"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getReimbursements,
  createReimbursement,
  approveReimbursement,
  rejectReimbursement,
  markReimbursementPaid,
  reopenReimbursement,
} from "@/modules/hr/api/reimbursements";

const KEY = ["hr", "reimbursements"] as const;
const NOTIF_KEY = ["hr", "me", "notifications"] as const;

export function useReimbursements() {
  return useQuery({ queryKey: KEY, queryFn: getReimbursements });
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
