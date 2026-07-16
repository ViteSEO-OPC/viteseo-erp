"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getLeaveTypes,
  getMyLeaveBalances,
  getLeaveRequests,
  createLeaveRequest,
  approveLeaveRequest,
  rejectLeaveRequest,
} from "@/modules/hr/api/leave";
import type { StoreLeaveInput } from "@/modules/hr/types/leave";

const TYPES_KEY = ["hr", "leave-types"] as const;
const BALANCES_KEY = ["hr", "me", "leave-balances"] as const;
const REQUESTS_KEY = ["hr", "leave-requests"] as const;
const NOTIF_KEY = ["hr", "me", "notifications"] as const;

export function useLeaveTypes() {
  return useQuery({ queryKey: TYPES_KEY, queryFn: getLeaveTypes });
}

export function useMyLeaveBalances() {
  return useQuery({ queryKey: BALANCES_KEY, queryFn: getMyLeaveBalances });
}

export function useLeaveRequests() {
  return useQuery({ queryKey: REQUESTS_KEY, queryFn: getLeaveRequests });
}

export function useCreateLeaveRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: StoreLeaveInput) => createLeaveRequest(input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: REQUESTS_KEY });
      qc.invalidateQueries({ queryKey: BALANCES_KEY });
      qc.invalidateQueries({ queryKey: NOTIF_KEY });
    },
  });
}

// Approve/reject refresh the queue, balances, AND notifications — that's how the
// employee's balance card drops (§5.5a) and the HR badge updates immediately.
export function useApproveLeaveRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => approveLeaveRequest(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: REQUESTS_KEY });
      qc.invalidateQueries({ queryKey: BALANCES_KEY });
      qc.invalidateQueries({ queryKey: NOTIF_KEY });
    },
  });
}

export function useRejectLeaveRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      rejectLeaveRequest(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: REQUESTS_KEY });
      qc.invalidateQueries({ queryKey: BALANCES_KEY });
      qc.invalidateQueries({ queryKey: NOTIF_KEY });
    },
  });
}
