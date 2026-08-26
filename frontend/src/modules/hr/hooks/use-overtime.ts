"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { QueueParams } from "@/modules/hr/api/queue";

import {
  getAutoOvertimeReview,
  getAutoOvertimeTotals,
  getOvertimeRequests,
  getOvertimeSettings,
  classifyOvertime,
  createOvertimeRequest,
  createOvertimeRequestForEmployee,
  approveOvertimeRequest,
  rejectOvertimeRequest,
  cancelOvertimeRequest,
  reopenOvertimeRequest,
} from "@/modules/hr/api/overtime";

const KEY = ["hr", "overtime-requests"] as const;

/** One page of the overtime queue, with the total. Filtered and ordered by the database. */
export function useOvertimeRequests(params: QueueParams) {
  return useQuery({
    queryKey: [...KEY, params] as const,
    queryFn: () => getOvertimeRequests(params),
    // Turning a page swaps the rows rather than emptying the table and rebuilding it.
    placeholderData: keepPreviousData,
  });
}

/** Auto-mode overtime the caps held back, for the period shown. */
/**
 * The two figures above the review — held and flagged minutes, with their day counts.
 *
 * Its own query rather than a sum of the rows on screen: the review is paged, and both figures
 * are minutes of pay.
 */
export function useAutoOvertimeTotals(start: string, end: string) {
  return useQuery({
    queryKey: ["hr", "overtime-auto-review", "totals", start, end] as const,
    queryFn: () => getAutoOvertimeTotals(start, end),
    enabled: !!start && !!end,
  });
}

/** One page of the auto-overtime review. `status` filters the row's derived `kind`. */
export function useAutoOvertimeReview(start: string, end: string, params: QueueParams) {
  return useQuery({
    queryKey: ["hr", "overtime-auto-review", start, end, params] as const,
    queryFn: () => getAutoOvertimeReview(start, end, params),
    enabled: !!start && !!end,
    placeholderData: keepPreviousData,
  });
}

/** Whether overtime can be requested at all — drives the form vs. an explanatory note. */
export function useOvertimeSettings() {
  return useQuery({
    queryKey: ["hr", "overtime-settings"] as const,
    queryFn: getOvertimeSettings,
    staleTime: 5 * 60 * 1000,
  });
}

/** Live day classification for the filing form — only runs once a date is picked. */
export function useOvertimeClassification(date: string) {
  return useQuery({
    queryKey: ["hr", "overtime-classification", date] as const,
    queryFn: () => classifyOvertime(date),
    enabled: !!date,
    staleTime: 5 * 60 * 1000,
  });
}

export function useCreateOvertimeRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createOvertimeRequest,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

/**
 * Item 10 — HR filing for an employee, retroactively. Invalidates the auto-review queue too: a
 * correction on a date that queue is reporting changes what is still outstanding there.
 */
export function useCreateOvertimeRequestForEmployee() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createOvertimeRequestForEmployee,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      qc.invalidateQueries({ queryKey: ["hr", "overtime-auto-review"] });
    },
  });
}

export function useApproveOvertimeRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => approveOvertimeRequest(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      // An approval is what releases held overtime, so the review queue changes too.
      qc.invalidateQueries({ queryKey: ["hr", "overtime-auto-review"] });
    },
  });
}

export function useCancelOvertimeRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      cancelOvertimeRequest(id, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useRejectOvertimeRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      rejectOvertimeRequest(id, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useReopenOvertimeRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) =>
      reopenOvertimeRequest(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: KEY });
      // Approved overtime shows on the attendance day it was worked.
      qc.invalidateQueries({ queryKey: ["hr", "attendance"] });
    },
  });
}
