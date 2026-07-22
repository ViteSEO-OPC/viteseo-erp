"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { archiveFilterParams } from "@/modules/crm/features/settings/hooks/use-archived-leads";
import type { ArchiveFilters } from "@/modules/crm/features/settings/types/archive-types";

/** Query caches a restore affects: the archive list shrinks; the active data surfaces gain the lead. */
function invalidateAfterRestore(queryClient: ReturnType<typeof useQueryClient>): void {
  queryClient.invalidateQueries({ queryKey: queryKeys.archive.all });
  queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
  queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all }); // counts / recent activity
  // A restored lead re-enters the BD's assigned pool → My Performance computed values (Assigned /
  // Active / Estimated Pipeline / Revenue) change. Symmetric with the delete path, which already
  // invalidates performance.all; without this, My Performance shows stale computed figures.
  queryClient.invalidateQueries({ queryKey: queryKeys.performance.all });
  queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all }); // lead.restored Activity
}

/** Restore ONE archived lead (POST /leads/{id}/restore). */
export function useRestoreLead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) =>
      apiClient.post(`/leads/${id}/restore`).then((r) => r.data.data as { id: string }),
    onSuccess: () => {
      invalidateAfterRestore(queryClient);
      toast.success("Lead restored");
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to restore lead"));
    },
  });
}

/** Vars for bulk restore: pass `ids` (the checked rows) OR `filters` (restore everything matching). */
export type RestoreManyVars = { ids: string[] } | { filters: ArchiveFilters };

/**
 * Bulk restore (POST /leads/restore-all). One endpoint, two actions: explicit `ids` restore the
 * selected rows; `filters` restore the whole current filter set. The backend funnels either through
 * the same per-record restore path, so this is one workflow with one Activity per lead.
 */
export function useRestoreLeads() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (vars: RestoreManyVars): Promise<number> => {
      const body: Record<string, unknown> =
        "ids" in vars
          ? { ids: vars.ids.map((id) => Number(id)) }
          : { ...archiveFilterParams(vars.filters) };
      const res = await apiClient.post("/leads/restore-all", body);
      return (res.data.data?.restored ?? 0) as number;
    },
    onSuccess: (restored) => {
      invalidateAfterRestore(queryClient);
      toast.success(`Restored ${restored} lead${restored === 1 ? "" : "s"}`);
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to restore leads"));
    },
  });
}
