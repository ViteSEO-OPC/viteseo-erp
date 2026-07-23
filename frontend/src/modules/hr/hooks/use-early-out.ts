"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getEarlyOutRequests,
  createEarlyOutRequest,
  approveEarlyOutRequest,
  rejectEarlyOutRequest,
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
