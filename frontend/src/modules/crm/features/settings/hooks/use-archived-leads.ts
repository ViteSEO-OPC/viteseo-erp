"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type {
  ArchivedLead,
  ArchiveFilters,
  UserRef,
} from "@/modules/crm/features/settings/types/archive-types";

/**
 * Maps the archive filter UI state to backend query params, dropping the "all"/empty sentinels so
 * an unset filter is simply omitted. Shared by the list hook and the bulk-restore mutation so the
 * "restore all filtered" batch matches exactly what the list is showing.
 */
export function archiveFilterParams(filters: ArchiveFilters): Record<string, string> {
  const params: Record<string, string> = {};
  if (filters.search?.trim()) params.search = filters.search.trim();
  if (filters.year && filters.year !== "all") params.year = filters.year;
  if (filters.status && filters.status !== "all") params.status = filters.status;
  if (filters.assignedBd && filters.assignedBd !== "all") params.assigned_bd = filters.assignedBd;
  if (filters.archivedBy && filters.archivedBy !== "all") params.archived_by = filters.archivedBy;
  return params;
}

export type ArchivedLeadsResult = {
  leads: ArchivedLead[];
  /** Count matching the current filters (drives pagination). */
  total: number;
  /** Total archived leads in scope, independent of filters (drives the header count). */
  totalArchived: number;
  /** Distinct users who archived a visible lead (powers the "Archived By" filter). */
  archivers: UserRef[];
};

/**
 * Archived (soft-deleted) leads for the Archive Management page. Server-paginated (10/page, 0-indexed
 * `page` mapped to Laravel's 1-indexed). Every count and option comes from the backend — no client
 * computation. `keepPreviousData` avoids row flicker while paging/filtering.
 */
export function useArchivedLeads(filters: ArchiveFilters, page: number) {
  const query = useQuery({
    queryKey: queryKeys.archive.list({ ...archiveFilterParams(filters), page }),
    placeholderData: keepPreviousData,
    queryFn: async (): Promise<ArchivedLeadsResult> => {
      const res = await apiClient.get("/leads/archived", {
        params: { ...archiveFilterParams(filters), page: page + 1 },
      });
      return {
        leads: res.data.data as ArchivedLead[],
        total: res.data.meta?.total ?? 0,
        totalArchived: res.data.summary?.totalArchived ?? 0,
        archivers: (res.data.filters?.archivers ?? []) as UserRef[],
      };
    },
  });

  return {
    leads: query.data?.leads ?? [],
    total: query.data?.total ?? 0,
    totalArchived: query.data?.totalArchived ?? 0,
    archivers: query.data?.archivers ?? [],
    isLoading: query.isLoading,
    isError: query.isError,
    refetch: query.refetch,
  };
}
