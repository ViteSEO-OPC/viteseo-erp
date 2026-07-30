"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getHalfDayRequests,
  createHalfDayRequest,
  approveHalfDayRequest,
  rejectHalfDayRequest,
  reopenHalfDayRequest,
} from "@/modules/hr/api/half-day";

const KEY = ["hr", "half-day-requests"] as const;

export function useHalfDayRequests() {
  return useQuery({ queryKey: KEY, queryFn: getHalfDayRequests });
}

export function useCreateHalfDayRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createHalfDayRequest,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useApproveHalfDayRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => approveHalfDayRequest(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useRejectHalfDayRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      rejectHalfDayRequest(id, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

// An approved half-day is half an unpaid day, so undoing it moves attendance too.
export function useReopenHalfDayRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) =>
      reopenHalfDayRequest(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: ["hr", "attendance"] });
    },
  });
}
