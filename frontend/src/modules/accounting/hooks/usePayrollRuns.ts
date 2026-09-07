import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type { PayrollRun } from "@/modules/accounting/types";

export const payrollRunKeys = {
  all: ["accounting", "payroll-runs"] as const,
};

/** HR payroll runs with their true employer cost + posted flag. */
export function usePayrollRuns() {
  return useQuery({
    queryKey: payrollRunKeys.all,
    queryFn: async (): Promise<PayrollRun[]> => {
      const { data } = await apiClient.get("/accounting/payroll-runs");
      return data.data as PayrollRun[];
    },
  });
}

/** Post a payroll run to the ledger, then refresh the runs, journal and trial balance. */
export function usePostPayrollRun() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (runId: number) => {
      const { data } = await apiClient.post(
        `/accounting/payroll-runs/${runId}/post-to-ledger`,
      );
      return data.data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: payrollRunKeys.all });
      qc.invalidateQueries({ queryKey: ["accounting", "journal-entries"] });
      qc.invalidateQueries({ queryKey: ["accounting", "trial-balance"] });
    },
  });
}

/** Import payroll from a CSV (standalone / external HRIS) and post it to the ledger. */
export function useImportPayroll() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (vars: { file: File; entry_date: string; memo?: string }) => {
      const form = new FormData();
      form.append("file", vars.file);
      form.append("entry_date", vars.entry_date);
      if (vars.memo) form.append("memo", vars.memo);
      const { data } = await apiClient.post("/accounting/payroll-import", form);
      return data.data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["accounting"] }),
  });
}
