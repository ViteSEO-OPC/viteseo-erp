"use client";

import { useQuery } from "@tanstack/react-query";

import { getPendingApprovals } from "@/modules/hr/api/approvals";

/** The approvals card's whole list, in one request. */
export const PENDING_APPROVALS_KEY = ["hr", "approvals", "pending"] as const;

export function usePendingApprovals(limit = 50) {
  return useQuery({
    queryKey: [...PENDING_APPROVALS_KEY, limit] as const,
    queryFn: () => getPendingApprovals(limit),
  });
}
