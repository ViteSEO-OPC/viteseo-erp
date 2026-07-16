"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/** A lead option for the event form's optional "Related lead" selector. */
export type PlannerLeadOption = {
  id: string;
  business: string;
  industry: string | null;
};

type LeadRow = { id: string; business: string; industry: string | null };

/**
 * Lead options for linking an event to a lead. Reuses the ownership-scoped `/leads` list (a BD
 * sees only their own leads). Capped at 100 — enough for the current single-BD workspace; a
 * searchable picker can replace this if a BD's book grows past that.
 */
export function usePlannerLeads() {
  return useQuery<PlannerLeadOption[]>({
    queryKey: [...queryKeys.planner.all, "lead-options"],
    queryFn: () =>
      apiClient
        .get("/leads", { params: { per_page: 100 } })
        .then((r) =>
          ((r.data.data as LeadRow[]) ?? []).map((l) => ({
            id: String(l.id),
            business: l.business,
            industry: l.industry,
          })),
        ),
  });
}
