import { useQuery } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type { TrialBalance } from "@/modules/accounting/types";

export const trialBalanceKeys = {
  all: ["accounting", "trial-balance"] as const,
};

/** Trial balance for the current org (GET /accounting/reports/trial-balance). */
export function useTrialBalance() {
  return useQuery({
    queryKey: trialBalanceKeys.all,
    queryFn: async (): Promise<TrialBalance> => {
      const { data } = await apiClient.get("/accounting/reports/trial-balance");
      return data.data as TrialBalance;
    },
  });
}
