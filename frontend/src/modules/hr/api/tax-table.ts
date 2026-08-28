import { apiClient } from "@/core/api-client";
import type { TaxBandInput, TaxTable } from "@/modules/hr/types/payroll";

/** The withholding-tax bands in force on `asOf`, plus every loaded version. */
export async function getTaxTable(asOf: string): Promise<TaxTable> {
  const res = await apiClient.get("/hr/tax-table", { params: { as_of: asOf } });
  return res.data.data;
}

/**
 * Publish a table, in force from today. No date is sent: a rate takes effect forward, and
 * saving twice in a day replaces the day's table rather than stacking versions.
 */
export async function saveTaxTable(bands: TaxBandInput[], note?: string | null): Promise<void> {
  await apiClient.put("/hr/tax-table", { bands, note: note || null });
}

/** Undo a published table. The last remaining one cannot be removed. */
export async function deleteTaxVersion(id: number): Promise<void> {
  await apiClient.delete(`/hr/tax-versions/${id}`);
}
