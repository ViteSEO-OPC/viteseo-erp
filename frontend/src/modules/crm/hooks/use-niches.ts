"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/** One registered Business Niche. `name` IS the value stored on a lead (`lead.industry`). */
export type Niche = {
  id: string;
  name: string;
  /** false = built-in (approved canonical vocabulary): never renamable or deletable. */
  isCustom: boolean;
  /** Live leads currently carrying this niche — drives the modal's delete guard copy. */
  leadCount: number;
};

/**
 * The Business Niche registry, already ordered by the API: built-ins first, then custom, each
 * alphabetical — exactly the order the selector renders. The order is NOT re-sorted here so the
 * dropdown, the modal and any future consumer can never disagree about it.
 */
export function useNiches(enabled = true) {
  const query = useQuery({
    queryKey: queryKeys.niches.list(),
    enabled,
    queryFn: async (): Promise<Niche[]> => {
      const res = await apiClient.get("/niches");
      return res.data.data as Niche[];
    },
  });

  return {
    niches: query.data ?? [],
    isLoading: query.isLoading,
    isError: query.isError,
  };
}

/**
 * Register a custom niche.
 *
 * Only the registry changes — no lead's `industry` is touched — so this invalidates the niche list
 * and nothing else. The Leads/Reports/Dashboard caches all read lead data, which a new (unused)
 * niche cannot have changed.
 */
export function useCreateNiche() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (name: string) =>
      apiClient.post("/niches", { name }).then((r) => r.data.data as Niche),
    onSuccess: (niche) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.niches.all });
      toast.success(`Business niche "${niche.name}" created`);
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to create business niche"));
    },
  });
}

/**
 * Rename a custom niche.
 *
 * Unlike create, this DOES rewrite lead data: the server repoints every lead holding the old
 * `industry` string, so every surface that reads a lead's niche has to be refreshed — the Leads
 * list and its niche chips, the Reports "Leads by niche" card (a separate cache entry from the
 * leads list despite sharing an endpoint), and the Dashboard's Recent Leads industry column.
 */
export function useRenameNiche() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, name }: { id: string; name: string }) =>
      apiClient.patch(`/niches/${id}`, { name }).then((r) => r.data.data as Niche),
    onSuccess: (niche) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.niches.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
      queryClient.invalidateQueries({ queryKey: queryKeys.reports.industryBars() });
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all });
      toast.success(`Renamed to "${niche.name}"`);
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to rename business niche"));
    },
  });
}

/**
 * Delete a custom niche. The server refuses if any lead (including archived ones) still uses it,
 * so a successful delete cannot have changed lead data — only the registry is invalidated.
 */
export function useDeleteNiche() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => apiClient.delete(`/niches/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.niches.all });
      toast.success("Business niche deleted");
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to delete business niche"));
    },
  });
}
