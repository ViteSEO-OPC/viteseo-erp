"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import {
  useNicheDistribution,
  type NicheDistributionItem,
} from "@/modules/crm/features/leads/hooks/use-niche-distribution";

/** How many top industries the Reports "Leads by niche" bar list shows. */
const TOP_N = 8;
const EMPTY: Record<string, number> = {};

/**
 * "Leads by niche" for Management Reports — REUSE only. The `/leads` endpoint already returns a real
 * SQL `industry_counts` aggregate (GROUP BY industry over the whole dataset, page/filter-independent;
 * industry IS the niche — the backend does no taxonomy/mapping). We reuse that endpoint and the
 * existing `useNicheDistribution` transform (+ deterministic colours), then surface the top N by count.
 * No new endpoint, no duplicated business computation, no invented canonical niches.
 */
export function useLeadsNiche(): {
  top: NicheDistributionItem[];
  max: number;
  total: number;
  isLoading: boolean;
  isError: boolean;
} {
  const query = useQuery({
    queryKey: queryKeys.reports.industryBars(),
    queryFn: async () => {
      const res = await apiClient.get("/leads");
      return {
        counts: (res.data.industry_counts ?? EMPTY) as Record<string, number>,
        total: (res.data.meta?.total ?? 0) as number,
      };
    },
  });

  const dist = useNicheDistribution(query.data?.counts ?? EMPTY, query.data?.total ?? 0);
  const top = [...dist.items].sort((a, b) => b.count - a.count).slice(0, TOP_N);

  return {
    top,
    max: top[0]?.count ?? 0,
    total: dist.total,
    isLoading: query.isLoading,
    isError: query.isError,
  };
}
