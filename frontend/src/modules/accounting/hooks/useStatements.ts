import { useQuery } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type { BalanceSheet, ProfitAndLoss } from "@/modules/accounting/types";

/** Profit & Loss for a month (GET /accounting/reports/profit-and-loss). */
export function useProfitAndLoss(period: string) {
  return useQuery({
    queryKey: ["accounting", "profit-and-loss", period],
    queryFn: async (): Promise<ProfitAndLoss> => {
      const { data } = await apiClient.get("/accounting/reports/profit-and-loss", {
        params: { period },
      });
      return data.data as ProfitAndLoss;
    },
  });
}

/** Balance Sheet as of a date (GET /accounting/reports/balance-sheet). */
export function useBalanceSheet(asOf: string) {
  return useQuery({
    queryKey: ["accounting", "balance-sheet", asOf],
    queryFn: async (): Promise<BalanceSheet> => {
      const { data } = await apiClient.get("/accounting/reports/balance-sheet", {
        params: { as_of: asOf },
      });
      return data.data as BalanceSheet;
    },
  });
}
