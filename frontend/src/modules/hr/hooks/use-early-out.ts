"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getEarlyOutRequests,
  createEarlyOutRequest,
  approveEarlyOutRequest,
  rejectEarlyOutRequest,
  reopenEarlyOutRequest,
} from "@/modules/hr/api/early-out";

const KEY = ["hr", "early-out-requests"] as const;

export function useEarlyOutRequests() {
  return useQuery({ queryKey: KEY, queryFn: getEarlyOutRequests });
}

export function useCreateEarlyOutRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createEarlyOutRequest,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useApproveEarlyOutRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => approveEarlyOutRequest(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useRejectEarlyOutRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      rejectEarlyOutRequest(id, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

// An approved early-out docks the undertime fraction, so undoing it moves attendance too.
export function useReopenEarlyOutRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) =>
      reopenEarlyOutRequest(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: ["hr", "attendance"] });
    },
  });
}
