"use client";

import { useQuery } from "@tanstack/react-query";

import { getHrDashboard } from "@/modules/hr/api/dashboard";

export function useHrDashboard() {
  return useQuery({
    queryKey: ["hr", "dashboard", "stats"],
    queryFn: getHrDashboard,
    refetchInterval: 60_000,
  });
}
