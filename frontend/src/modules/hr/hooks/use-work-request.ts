"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { QueueParams } from "@/modules/hr/api/queue";

import {
  getWorkRequests,
  createWorkRequest,
  approveWorkRequest,
  rejectWorkRequest,
  reopenWorkRequest,
  cancelWorkRequest,
} from "@/modules/hr/api/work-request";

const KEY = ["hr", "work-requests"] as const;
/** The employee's own clock panel — every decision here changes what it may offer. */
const MY_ATTENDANCE_KEY = ["hr", "me", "attendance"] as const;

/** One page of the work-request queue, with the total. Filtered and ordered by the database. */
export function useWorkRequests(params: QueueParams) {
  return useQuery({
    queryKey: [...KEY, params] as const,
    queryFn: () => getWorkRequests(params),
    // Turning a page swaps the rows rather than emptying the table and rebuilding it.
    placeholderData: keepPreviousData,
  });
}

export function useCreateWorkRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (reason?: string) => createWorkRequest(reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      // The panel's button must flip to "Waiting for HR" immediately — it reads the live
      // request out of the clock-in window, not out of this list.
      qc.invalidateQueries({ queryKey: MY_ATTENDANCE_KEY });
    },
  });
}

/** Approving writes an attendance row, so the HR roster moves too. */
export function useApproveWorkRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => approveWorkRequest(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: ["hr", "attendance"] });
      qc.invalidateQueries({ queryKey: MY_ATTENDANCE_KEY });
    },
  });
}

export function useRejectWorkRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => rejectWorkRequest(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: MY_ATTENDANCE_KEY });
    },
  });
}

/** Reopening an approved one DELETES the attendance row it created. */
export function useReopenWorkRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) => reopenWorkRequest(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: ["hr", "attendance"] });
      qc.invalidateQueries({ queryKey: MY_ATTENDANCE_KEY });
    },
  });
}

export function useCancelWorkRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => cancelWorkRequest(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: MY_ATTENDANCE_KEY });
    },
  });
}
