"use client";

import { useQuery } from "@tanstack/react-query";

import { getTaxTable } from "@/modules/hr/api/tax-table";

/** The withholding-tax bands in force on a date. */
export function useTaxTable(asOf: string) {
  return useQuery({
    queryKey: ["hr", "tax-table", asOf] as const,
    queryFn: () => getTaxTable(asOf),
    enabled: !!asOf,
    staleTime: 60 * 60 * 1000,
  });
}
