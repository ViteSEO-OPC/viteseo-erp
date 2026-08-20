import { apiClient } from "@/core/api-client";
import type { TaxTable } from "@/modules/hr/types/payroll";

/** The withholding-tax bands in force on `asOf`, plus every loaded version. */
export async function getTaxTable(asOf: string): Promise<TaxTable> {
  const res = await apiClient.get("/hr/tax-table", { params: { as_of: asOf } });
  return res.data.data;
}
