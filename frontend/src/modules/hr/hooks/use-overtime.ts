"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getOvertimeRequests,
  getOvertimeSettings,
  classifyOvertime,
  createOvertimeRequest,
  approveOvertimeRequest,
  rejectOvertimeRequest,
  cancelOvertimeRequest,
  reopenOvertimeRequest,
} from "@/modules/hr/api/overtime";

const KEY = ["hr", "overtime-requests"] as const;

export function useOvertimeRequests() {
  return useQuery({ queryKey: KEY, queryFn: getOvertimeRequests });
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

export function useApproveOvertimeRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => approveOvertimeRequest(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
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
