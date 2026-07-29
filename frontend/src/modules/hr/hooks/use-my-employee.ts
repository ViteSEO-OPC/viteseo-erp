"use client";

import { useQuery } from "@tanstack/react-query";

import { getMyEmployee } from "@/modules/hr/api/employees";

/**
 * The caller's own employee record. `enabled: false` for accounts that have no record
 * (a bare admin) — the endpoint 404s for them, and the shell asks on every page.
 */
export function useMyEmployee(options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: ["hr", "me", "employee"],
    queryFn: getMyEmployee,
    enabled: options?.enabled ?? true,
  });
}
