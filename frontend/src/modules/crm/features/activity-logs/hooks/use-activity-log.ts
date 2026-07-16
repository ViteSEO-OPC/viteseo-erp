"use client";

import { useInfiniteQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { mapActivity, type ActivityApi } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";
import type { ActivityItem } from "@/modules/crm/features/dashboard/types/dashboard-types";

type ActivitiesResponse = {
  data: ActivityApi[];
  meta: { current_page: number; last_page: number; total: number };
};

/**
 * Full activity history for the Activity Logs page. Reuses the SAME source as the Dashboard
 * Recent Activity widget — the paginated `GET /activities` endpoint (15/page) and the shared
 * `mapActivity` mapping — with no new activity source or business logic. The Dashboard shows a
 * recent preview; this consumes the endpoint's existing pagination to show the full history via
 * incremental "load more".
 */
export function useActivityLog() {
  const query = useInfiniteQuery({
    queryKey: queryKeys.activityLogs.list({}),
    queryFn: ({ pageParam }) =>
      apiClient
        .get<ActivitiesResponse>("/activities", { params: { page: pageParam } })
        .then((r) => r.data),
    initialPageParam: 1,
    getNextPageParam: (lastPage) =>
      lastPage.meta.current_page < lastPage.meta.last_page
        ? lastPage.meta.current_page + 1
        : undefined,
  });

  const activities: ActivityItem[] =
    query.data?.pages.flatMap((page) => page.data.map(mapActivity)) ?? [];

  return {
    activities,
    total: query.data?.pages[0]?.meta.total ?? 0,
    isLoading: query.isLoading,
    isError: query.isError,
    hasNextPage: query.hasNextPage,
    isFetchingNextPage: query.isFetchingNextPage,
    fetchNextPage: query.fetchNextPage,
    retry: () => query.refetch(),
  };
}
