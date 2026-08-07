"use client";

import { useQuery } from "@tanstack/react-query";

import { getTaxTable } from "@/modules/hr/api/tax-table";

/**
 * The withholding-tax bands in force on a date (FIN-027).
 *
 * Keyed on the date so switching it refetches rather than showing the previous table. `staleTime` is
 * generous because tax versions change by migration, not by anything a user does in the app.
 */
export function useTaxTable(asOf: string) {
  return useQuery({
    queryKey: ["hr", "tax-table", asOf] as const,
    queryFn: () => getTaxTable(asOf),
    enabled: !!asOf,
    staleTime: 60 * 60 * 1000,
  });
}
