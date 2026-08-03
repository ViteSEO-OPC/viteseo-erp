"use client";

import { keepPreviousData, useInfiniteQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys, type NotificationHistoryFilters } from "@/modules/crm/lib/query-keys";
import type { NotificationDto } from "@/modules/crm/features/notifications/types/notification-types";

/** The cursor envelope — `GET /notifications?paginate=cursor`. `next_cursor` is null on the last page. */
type HistoryResponse = {
  data: NotificationDto[];
  meta: { next_cursor: string | null; per_page: number };
};

const PER_PAGE = 20;

/**
 * The Notification History feed.
 *
 * Reads the SAME endpoint the dropdown reads, opting into cursor pagination
 * (`paginate=cursor`) because this feed grows at the head: Slice 3 pushes new
 * notifications to the top in realtime, and an offset would renumber every row
 * beneath one that arrives mid-read, so "next page" would serve rows already seen.
 * A cursor is anchored to a row, so head insertions cannot move it.
 *
 * No new endpoint and no new notification source — filters, search and read state all
 * come from the Slice 2 API. The dropdown's own query is untouched and still offset.
 */
export function useNotificationHistory(filters: NotificationHistoryFilters) {
  const query = useInfiniteQuery({
    queryKey: queryKeys.notifications.history(filters),
    queryFn: ({ pageParam }) =>
      apiClient
        .get<HistoryResponse>("/notifications", {
          params: {
            paginate: "cursor",
            per_page: PER_PAGE,
            // Only send what is actually set: an empty `search` or a null `type` would
            // be sent as a blank query parameter and validated as a real (empty) value.
            filter: filters.filter,
            ...(filters.type ? { type: filters.type } : {}),
            ...(filters.search.trim() ? { search: filters.search.trim() } : {}),
            ...(pageParam ? { cursor: pageParam } : {}),
          },
        })
        .then((r) => r.data),
    // The first page asks for no cursor at all; the server anchors it at the head.
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor ?? undefined,
    // Every filter combination is its own key, so without this each debounced
    // keystroke would blank the list to skeletons and back — the reader loses their
    // place on every letter. Holding the previous results puts the page in a
    // "refining" state instead of a "loading" one; `isPlaceholderData` is what the
    // page dims on. Same reasoning, and same primitive, as use-archived-leads.
    placeholderData: keepPreviousData,
  });

  const notifications: NotificationDto[] =
    query.data?.pages.flatMap((page) => page.data) ?? [];

  return {
    notifications,
    isLoading: query.isLoading,
    isError: query.isError,
    isFetching: query.isFetching,
    /** Showing the previous filter's rows while the new ones load. */
    isRefining: query.isPlaceholderData,
    hasNextPage: query.hasNextPage,
    isFetchingNextPage: query.isFetchingNextPage,
    fetchNextPage: query.fetchNextPage,
    retry: () => query.refetch(),
  };
}
