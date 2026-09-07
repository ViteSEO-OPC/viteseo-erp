import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";

export interface ReconLine {
  id: number;
  entry_date: string | null;
  reference: string | null;
  memo: string | null;
  debit: number;
  credit: number;
}

export interface ReconWorksheet {
  account: { id: number; code: string; name: string };
  as_of: string;
  beginning_balance: number;
  book_balance: number;
  lines: ReconLine[];
}

export interface ReconHistoryRow {
  id: number;
  account: { code: string; name: string } | null;
  statement_date: string | null;
  statement_balance: number;
  beginning_balance: number;
  cleared_balance: number;
  reconciled_at: string | null;
}

/** The worksheet of outstanding lines for an account as of a date. */
export function useReconWorksheet(accountId: number | null, asOf: string) {
  return useQuery({
    queryKey: ["accounting", "bank-rec", "worksheet", accountId, asOf],
    enabled: accountId != null,
    queryFn: async (): Promise<ReconWorksheet> => {
      const { data } = await apiClient.get("/accounting/bank-reconciliations/worksheet", {
        params: { account_id: accountId, as_of: asOf },
      });
      return data.data as ReconWorksheet;
    },
  });
}

export function useReconHistory(accountId: number | null) {
  return useQuery({
    queryKey: ["accounting", "bank-rec", "history", accountId],
    queryFn: async (): Promise<ReconHistoryRow[]> => {
      const { data } = await apiClient.get("/accounting/bank-reconciliations", {
        params: accountId ? { account_id: accountId } : {},
      });
      return data.data as ReconHistoryRow[];
    },
  });
}

export function useCompleteReconciliation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: {
      account_id: number;
      statement_date: string;
      statement_balance: number;
      cleared_line_ids: number[];
    }) => {
      const { data } = await apiClient.post("/accounting/bank-reconciliations", payload);
      return data.data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["accounting", "bank-rec"] });
    },
  });
}
