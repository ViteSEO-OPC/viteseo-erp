"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { deleteTaxVersion, getTaxTable, saveTaxTable } from "@/modules/hr/api/tax-table";
import type { TaxBandInput } from "@/modules/hr/types/payroll";

const TAX_TABLE_KEY = ["hr", "tax-table"] as const;

/** The withholding-tax bands in force on a date. */
export function useTaxTable(asOf: string) {
  return useQuery({
    queryKey: [...TAX_TABLE_KEY, asOf] as const,
    queryFn: () => getTaxTable(asOf),
    enabled: !!asOf,
    staleTime: 60 * 60 * 1000,
  });
}

/**
 * Publishing a table changes what payroll withholds, so this invalidates every date's copy —
 * not just today's — because a run priced at a cycle end now resolves differently.
 */
export function useSaveTaxTable() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ bands, note }: { bands: TaxBandInput[]; note?: string | null }) =>
      saveTaxTable(bands, note),
    onSuccess: () => qc.invalidateQueries({ queryKey: TAX_TABLE_KEY }),
  });
}

export function useDeleteTaxVersion() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteTaxVersion(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: TAX_TABLE_KEY }),
  });
}
