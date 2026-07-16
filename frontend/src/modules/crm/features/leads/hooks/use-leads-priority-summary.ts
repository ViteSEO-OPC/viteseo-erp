"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { PriorityLevel } from "@/modules/crm/features/leads/types/lead-types";

// Business copy for the Leads-page priority cards (PO-approved). Plain priority language — no
// website-opportunity wording. `tone` is always the priority level itself, so the coloured chip and
// the count always describe the SAME thing (the previous cards chipped website buckets as H/M/L).
const PRIORITY_META: Record<PriorityLevel, { title: string; description: string }> = {
  high: { title: "High Priority", description: "Requires immediate attention." },
  medium: { title: "Medium Priority", description: "Follow up as planned." },
  low: { title: "Low Priority", description: "Lower urgency opportunities." },
};

type LeadPriorityBucketApi = {
  priority: PriorityLevel;
  count: number;
  avgScore: number;
  totalPct: number;
};

export type LeadPriorityBucket = LeadPriorityBucketApi & {
  title: string;
  description: string;
};

/**
 * Live High/Medium/Low counts for the Leads-page summary cards, aggregated from `leads.priority`
 * (GET /leads/priority-summary). Filter-aware: `filters` carries the current table filters EXCEPT
 * priority (search/status/website_status/niche), so the cards always describe the same dataset the
 * table shows while still acting as a priority switcher. The counts read the exact same DB column
 * the table's Priority badge reads — the two views can never disagree.
 */
export function useLeadsPrioritySummary(filters: Record<string, string>) {
  const query = useQuery<LeadPriorityBucketApi[]>({
    queryKey: queryKeys.leads.prioritySummary(filters),
    queryFn: () =>
      apiClient
        .get("/leads/priority-summary", { params: filters })
        .then((r) => r.data.data),
    // Keep the previous counts on screen while a filter change refetches (no card flicker).
    placeholderData: (prev) => prev,
  });

  const data: LeadPriorityBucket[] | undefined = query.data?.map((bucket) => ({
    ...bucket,
    ...PRIORITY_META[bucket.priority],
  }));

  return { data, isLoading: query.isLoading, isError: query.isError };
}
