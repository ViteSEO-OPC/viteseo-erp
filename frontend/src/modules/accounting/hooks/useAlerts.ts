import { useQuery } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type { AccountingAlerts } from "@/modules/accounting/types";

const MINUTE = 60_000;

/**
 * Counts behind the Accounting bell (overdue AR/AP, bills due this week).
 * Cheap enough to poll: refreshed every minute so a payment recorded in
 * another tab clears the badge without a reload.
 */
export function useAccountingAlerts() {
  return useQuery({
    queryKey: ["accounting", "alerts"],
    queryFn: async (): Promise<AccountingAlerts> => {
      const { data } = await apiClient.get("/accounting/alerts");
      return data.data as AccountingAlerts;
    },
    staleTime: MINUTE,
    refetchInterval: MINUTE,
  });
}
