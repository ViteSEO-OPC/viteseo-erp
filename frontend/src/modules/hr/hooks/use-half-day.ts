"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { QueueParams } from "@/modules/hr/api/queue";

import {
  getHalfDayRequests,
  createHalfDayRequest,
  approveHalfDayRequest,
  rejectHalfDayRequest,
  reopenHalfDayRequest,
  cancelHalfDayRequest,
} from "@/modules/hr/api/half-day";

const KEY = ["hr", "half-day-requests"] as const;

/** One page of the half-day queue, with the total. Filtered and ordered by the database. */
export function useHalfDayRequests(params: QueueParams) {
  return useQuery({
    queryKey: [...KEY, params] as const,
    queryFn: () => getHalfDayRequests(params),
    // Turning a page swaps the rows rather than emptying the table and rebuilding it.
    placeholderData: keepPreviousData,
  });
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
// The employee withdrawing their own pending request. Reason required.
export function useCancelHalfDayRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => cancelHalfDayRequest(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: ["hr", "attendance"] });
    },
  });
}

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
