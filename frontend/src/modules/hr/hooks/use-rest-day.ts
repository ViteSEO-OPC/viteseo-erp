"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getRestDayRequests,
  createRestDayRequest,
  approveRestDayRequest,
  rejectRestDayRequest,
  reopenRestDayRequest,
  cancelRestDayRequest,
} from "@/modules/hr/api/rest-day";

const KEY = ["hr", "rest-day-requests"] as const;
/** The employee's own clock panel — every decision here changes what it may offer. */
const MY_ATTENDANCE_KEY = ["hr", "me", "attendance"] as const;

export function useRestDayRequests() {
  return useQuery({ queryKey: KEY, queryFn: getRestDayRequests });
}

export function useCreateRestDayRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (reason?: string) => createRestDayRequest(reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      // The panel's button must flip to "Waiting for HR" immediately — it reads the live
      // request out of the clock-in window, not out of this list.
      qc.invalidateQueries({ queryKey: MY_ATTENDANCE_KEY });
    },
  });
}

/** Approving writes an attendance row, so the HR roster moves too. */
export function useApproveRestDayRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => approveRestDayRequest(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: ["hr", "attendance"] });
      qc.invalidateQueries({ queryKey: MY_ATTENDANCE_KEY });
    },
  });
}

export function useRejectRestDayRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      rejectRestDayRequest(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: MY_ATTENDANCE_KEY });
    },
  });
}

/** Reopening an approved one DELETES the attendance row it created. */
export function useReopenRestDayRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) =>
      reopenRestDayRequest(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: ["hr", "attendance"] });
      qc.invalidateQueries({ queryKey: MY_ATTENDANCE_KEY });
    },
  });
}

export function useCancelRestDayRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      cancelRestDayRequest(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: MY_ATTENDANCE_KEY });
    },
  });
}
