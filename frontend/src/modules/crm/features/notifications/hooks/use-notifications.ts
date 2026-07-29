"use client";

import {
  useMutation,
  useQuery,
  useQueryClient,
  type InfiniteData,
  type QueryClient,
  type QueryKey,
} from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { NotificationDto } from "@/modules/crm/features/notifications/types/notification-types";

/** One cursor page of History — mirrors `HistoryResponse` in use-notification-history. */
type HistoryPage = {
  data: NotificationDto[];
  meta: { next_cursor: string | null; per_page: number };
};

/**
 * Every cache a notification row can be rendered from, plus the badge, captured so a
 * failed mutation can put all of them back exactly as they were.
 */
type CacheSnapshot = {
  list: NotificationDto[] | undefined;
  history: Array<[QueryKey, InfiniteData<HistoryPage> | undefined]>;
  count: number | undefined;
};

function snapshotCaches(qc: QueryClient): CacheSnapshot {
  return {
    list: qc.getQueryData<NotificationDto[]>(queryKeys.notifications.list()),
    history: qc.getQueriesData<InfiniteData<HistoryPage>>({
      queryKey: queryKeys.notifications.historyAll(),
    }),
    count: qc.getQueryData<number>(queryKeys.notifications.unreadCount()),
  };
}

function restoreCaches(qc: QueryClient, snapshot: CacheSnapshot): void {
  qc.setQueryData(queryKeys.notifications.list(), snapshot.list);
  snapshot.history.forEach(([key, data]) => qc.setQueryData(key, data));
  if (snapshot.count !== undefined) {
    qc.setQueryData(queryKeys.notifications.unreadCount(), snapshot.count);
  }
}

/**
 * The row's current read state, from whichever cache actually holds it.
 *
 * WHY THIS LOOKS IN TWO PLACES. The bell renders from `list()`; History renders from
 * its own cursor-paginated caches, one per filter set, and never populates `list()`.
 * Reading only `list()` meant a row clicked on History was simply not found, so the
 * badge got no optimistic delta and sat still until the server round-trip landed —
 * on a slow link, indistinguishable from a broken badge. Proven both ways by blocking
 * the PATCH: cold dropdown cache left the badge frozen, warm cache moved it.
 */
function findCachedNotification(qc: QueryClient, id: string): NotificationDto | undefined {
  const inList = qc
    .getQueryData<NotificationDto[]>(queryKeys.notifications.list())
    ?.find((n) => n.id === id);
  if (inList) return inList;

  for (const [, data] of qc.getQueriesData<InfiniteData<HistoryPage>>({
    queryKey: queryKeys.notifications.historyAll(),
  })) {
    const hit = data?.pages.flatMap((page) => page.data).find((n) => n.id === id);
    if (hit) return hit;
  }
  return undefined;
}

/**
 * Apply a read-state change to every cache holding the row, so the bell, the dropdown
 * list and every open History filter all move together instead of one surface waiting
 * on a refetch to catch up with another.
 */
function patchCachedRead(qc: QueryClient, id: string, read: boolean): void {
  const apply = (n: NotificationDto): NotificationDto =>
    n.id === id
      ? { ...n, read, read_at: read ? (n.read_at ?? new Date().toISOString()) : null }
      : n;

  qc.setQueryData<NotificationDto[]>(queryKeys.notifications.list(), (old) => old?.map(apply));
  qc.setQueriesData<InfiniteData<HistoryPage>>(
    { queryKey: queryKeys.notifications.historyAll() },
    (old) =>
      old && { ...old, pages: old.pages.map((page) => ({ ...page, data: page.data.map(apply) })) },
  );
}

/** Mark-all's cache half: every cached row becomes read, in every surface. */
function patchCachedReadAll(qc: QueryClient): void {
  const apply = (n: NotificationDto): NotificationDto =>
    n.read ? n : { ...n, read: true, read_at: n.read_at ?? new Date().toISOString() };

  qc.setQueryData<NotificationDto[]>(queryKeys.notifications.list(), (old) => old?.map(apply));
  qc.setQueriesData<InfiniteData<HistoryPage>>(
    { queryKey: queryKeys.notifications.historyAll() },
    (old) =>
      old && { ...old, pages: old.pages.map((page) => ({ ...page, data: page.data.map(apply) })) },
  );
}

/**
 * Unread count for the header badge — the single source of truth for the number the bell
 * renders. No background polling (approved 2026-07-16). Freshness has three sources, in
 * order of how often they matter: a realtime arrival patching this key directly
 * (use-notification-realtime), the optimistic deltas below, and invalidation of
 * `queryKeys.notifications.all` on every settle. Window-focus refetch is the backstop for
 * anything all three missed, which is why it opts back in against the CRM client default.
 */
export function useUnreadCount() {
  return useQuery({
    queryKey: queryKeys.notifications.unreadCount(),
    queryFn: async () => {
      const res = await apiClient.get<{ count: number }>("/notifications/unread-count");
      return res.data.count;
    },
    refetchOnWindowFocus: true,
  });
}

/**
 * The notification list (newest-first, server-ordered). Only fetched while the panel is open, and
 * kept fresh (staleTime 0) so re-opening always reflects the latest state.
 */
export function useNotifications(enabled: boolean) {
  return useQuery({
    queryKey: queryKeys.notifications.list(),
    queryFn: async () => {
      const res = await apiClient.get<{ data: NotificationDto[] }>("/notifications");
      return res.data.data;
    },
    enabled,
    staleTime: 0,
    refetchOnWindowFocus: true,
  });
}

/** Mark one notification read — optimistic so the row + badge update instantly, reconciled on settle. */
export function useMarkRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => apiClient.patch(`/notifications/${id}/read`),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: queryKeys.notifications.all });
      const snapshot = snapshotCaches(qc);
      // Only a row that is currently UNREAD moves the badge. Re-read from the cache
      // rather than trusted from the caller so a double-click is a no-op: the first
      // click's optimistic patch has already flipped the row this reads.
      const wasUnread = findCachedNotification(qc, id)?.read === false;
      patchCachedRead(qc, id, true);
      if (wasUnread && typeof snapshot.count === "number") {
        qc.setQueryData<number>(
          queryKeys.notifications.unreadCount(),
          Math.max(0, snapshot.count - 1),
        );
      }
      return snapshot;
    },
    onError: (_err, _id, snapshot) => {
      if (snapshot) restoreCaches(qc, snapshot);
    },
    onSettled: () => qc.invalidateQueries({ queryKey: queryKeys.notifications.all }),
  });
}

/**
 * Mark one notification UNREAD again — the undo for a misclick, and the History page's
 * only way back once a row has been read. Mirrors useMarkRead in the other direction:
 * same scoped endpoint, same cancel-then-optimistic shape, same reconcile on settle.
 *
 * Deliberately not a "dismiss": the row is never removed and nothing is deleted. Read
 * state is the only thing a user can change about a notification (PO decision
 * 2026-07-29) — archive, if it is ever wanted, would be a new field, not this.
 */
export function useMarkUnread() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => apiClient.patch(`/notifications/${id}/unread`),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: queryKeys.notifications.all });
      const snapshot = snapshotCaches(qc);
      // Only a row that is currently READ changes the count — re-marking an already
      // unread row is a no-op the badge must not double-count.
      const wasRead = findCachedNotification(qc, id)?.read === true;
      patchCachedRead(qc, id, false);
      if (wasRead && typeof snapshot.count === "number") {
        qc.setQueryData<number>(queryKeys.notifications.unreadCount(), snapshot.count + 1);
      }
      return snapshot;
    },
    onError: (_err, _id, snapshot) => {
      if (snapshot) restoreCaches(qc, snapshot);
    },
    onSettled: () => qc.invalidateQueries({ queryKey: queryKeys.notifications.all }),
  });
}

/** Mark every unread notification read — optimistic, then reconciled. */
export function useMarkAllRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => apiClient.patch("/notifications/read-all"),
    onMutate: async () => {
      await qc.cancelQueries({ queryKey: queryKeys.notifications.all });
      const snapshot = snapshotCaches(qc);
      patchCachedReadAll(qc);
      qc.setQueryData<number>(queryKeys.notifications.unreadCount(), 0);
      return snapshot;
    },
    onError: (_err, _vars, snapshot) => {
      if (snapshot) restoreCaches(qc, snapshot);
    },
    onSettled: () => qc.invalidateQueries({ queryKey: queryKeys.notifications.all }),
  });
}
