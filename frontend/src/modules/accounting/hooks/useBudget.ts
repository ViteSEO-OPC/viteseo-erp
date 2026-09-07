import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type { BudgetVsActual } from "@/modules/accounting/types";

export const budgetKeys = {
  period: (period: string) => ["accounting", "budgets", period] as const,
};

/** Budget vs actual for a month (GET /accounting/budgets?period=YYYY-MM). */
export function useBudget(period: string) {
  return useQuery({
    queryKey: budgetKeys.period(period),
    queryFn: async (): Promise<BudgetVsActual> => {
      const { data } = await apiClient.get("/accounting/budgets", {
        params: { period },
      });
      return data.data as BudgetVsActual;
    },
  });
}

/** Set a budget amount for one account in one period, then refresh that month. */
export function useSetBudget() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (vars: {
      period: string;
      account_id: number;
      amount: number;
    }) => {
      await apiClient.put("/accounting/budgets", vars);
    },
    onSuccess: (_data, vars) => {
      qc.invalidateQueries({ queryKey: budgetKeys.period(vars.period) });
    },
  });
}
