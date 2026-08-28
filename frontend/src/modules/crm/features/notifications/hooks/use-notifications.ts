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
import { STALE } from "@/modules/crm/lib/query-freshness";
import {
  NOTIFICATION_INBOX_WINDOW_DAYS,
  type NotificationDto,
} from "@/modules/crm/features/notifications/types/notification-types";

/** One cursor page of History — mirrors `HistoryResponse` in use-notification-history. */
type HistoryPage = {
  data: NotificationDto[];
  meta: { next_cursor: string | null; per_page: number };
};

/**
 * Which set of rows an unread count describes. `inbox` is the bell's — undismissed and
 * inside the window, i.e. exactly what the dropdown lists. `all` is History's.
 */
export type UnreadCountScope = "all" | "inbox";

/**
 * Every cache a notification row can be rendered from, plus BOTH badges, captured so a
 * failed mutation can put all of them back exactly as they were.
 *
 * The counts are snapshotted by prefix rather than by name: which scopes are cached
 * depends on what is mounted (the bell alone, or the bell plus History), and a snapshot
 * that assumed both would write a `0`-shaped hole into a scope that had never loaded.
 */
type CacheSnapshot = {
  list: NotificationDto[] | undefined;
  history: Array<[QueryKey, InfiniteData<HistoryPage> | undefined]>;
  counts: Array<[QueryKey, number | undefined]>;
};

function snapshotCaches(qc: QueryClient): CacheSnapshot {
  return {
    list: qc.getQueryData<NotificationDto[]>(queryKeys.notifications.list()),
    history: qc.getQueriesData<InfiniteData<HistoryPage>>({
      queryKey: queryKeys.notifications.historyAll(),
    }),
    counts: qc.getQueriesData<number>({ queryKey: queryKeys.notifications.unreadCounts() }),
  };
}

function restoreCaches(qc: QueryClient, snapshot: CacheSnapshot): void {
  qc.setQueryData(queryKeys.notifications.list(), snapshot.list);
  snapshot.history.forEach(([key, data]) => qc.setQueryData(key, data));
  snapshot.counts.forEach(([key, count]) => qc.setQueryData(key, count));
}

/**
 * Nudge one badge by a delta, but only if it has actually loaded.
 *
 * An uncached count is left alone rather than seeded: its own query will fetch the
 * true number, and inventing `-1 + 1` for a badge nobody has rendered yet would put a
 * wrong value on screen the moment that surface mounts.
 */
function bumpUnreadCount(qc: QueryClient, scope: UnreadCountScope, delta: number): void {
  qc.setQueryData<number>(queryKeys.notifications.unreadCount(scope), (prev) =>
    typeof prev === "number" ? Math.max(0, prev + delta) : prev,
  );
}

const INBOX_WINDOW_MS = NOTIFICATION_INBOX_WINDOW_DAYS * 24 * 60 * 60 * 1000;

/**
 * Whether a row is one the BELL counts, as opposed to one only History counts.
 *
 * Derived from the row itself rather than from which cache it was found in, because
 * the two are not the same question: a row clicked on History may well be inside the
 * bell's window, and a row that has aged out may still be sitting in a stale dropdown
 * cache. Getting this wrong is not fatal — `onSettled` reconciles every count from the
 * server — but it is the difference between a badge that moves once and one that
 * moves, moves back, and moves again.
 */
function isInInbox(notification: NotificationDto | undefined): boolean {
  if (!notification || notification.dismissed || !notification.created_at) return false;
  return Date.now() - new Date(notification.created_at).getTime() <= INBOX_WINDOW_MS;
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
 * Run a row transform across every cache a notification can be rendered from, so the
 * dropdown list and every open History filter move together instead of one surface
 * waiting on a refetch to catch up with another.
 *
 * The three mutations below differ only in that transform; the traversal — one plain
 * array plus N cursor-paginated infinite caches — is identical and lives here once.
 */
function patchCaches(qc: QueryClient, apply: (n: NotificationDto) => NotificationDto): void {
  qc.setQueryData<NotificationDto[]>(queryKeys.notifications.list(), (old) => old?.map(apply));
  qc.setQueriesData<InfiniteData<HistoryPage>>(
    { queryKey: queryKeys.notifications.historyAll() },
    (old) =>
      old && { ...old, pages: old.pages.map((page) => ({ ...page, data: page.data.map(apply) })) },
  );
}

/** Apply a read-state change to one row, everywhere it is cached. */
function patchCachedRead(qc: QueryClient, id: string, read: boolean): void {
  patchCaches(qc, (n) =>
    n.id === id
      ? { ...n, read, read_at: read ? (n.read_at ?? new Date().toISOString()) : null }
      : n,
  );
}

/** Mark-all's cache half: every cached row becomes read, in every surface. */
function patchCachedReadAll(qc: QueryClient): void {
  patchCaches(qc, (n) =>
    n.read ? n : { ...n, read: true, read_at: n.read_at ?? new Date().toISOString() },
  );
}

/**
 * Dismissal's cache half.
 *
 * Two distinct effects, because dismissing means two different things to the two
 * surfaces. The dropdown DROPS the row — that instant disappearance is the whole point
 * of the button. History KEEPS it and merely records the new state, since History is
 * the complete record and dismissing is not deletion.
 *
 * The read flags are set here too, mirroring the server: it marks a dismissed row read
 * in the same write, so leaving the cached copy unread would show History a row the
 * backend already considers acknowledged.
 */
function patchCachedDismissed(qc: QueryClient, id: string): void {
  const now = new Date().toISOString();

  patchCaches(qc, (n) =>
    n.id === id
      ? { ...n, dismissed: true, dismissed_at: n.dismissed_at ?? now, read: true, read_at: n.read_at ?? now }
      : n,
  );

  qc.setQueryData<NotificationDto[]>(queryKeys.notifications.list(), (old) =>
    old?.filter((n) => n.id !== id),
  );
}

/**
 * Unread count for the header badge — the single source of truth for the number the bell
 * renders. No background polling (approved 2026-07-16). Freshness has three sources, in
 * order of how often they matter: a realtime arrival patching this key directly
 * (use-notification-realtime), the optimistic deltas below, and invalidation of
 * `queryKeys.notifications.all` on every settle. Window-focus refetch is the backstop for
 * anything all three missed, and is left explicit here so it survives any future change to the
 * module's defaults.
 *
 * Shell-level: the topbar bell mounts this on every CRM screen, so it carries {@link STALE}. The
 * three sources above all bypass `staleTime` — a realtime patch and an optimistic delta write the
 * cache directly, and invalidation refetches regardless of freshness — so the window only ever
 * suppresses a focus refetch of a count nothing has reported a change to.
 *
 * SCOPED, and the scope is not cosmetic. The bell asks for `inbox` so its number counts
 * the same rows its dropdown lists — count everything and the badge advertises
 * notifications the panel then refuses to show, which reads as a broken bell rather
 * than as a filter. History asks for the default `all`, because its Unread tab really
 * does span the whole feed.
 */
export function useUnreadCount(scope: UnreadCountScope = "all") {
  return useQuery({
    queryKey: queryKeys.notifications.unreadCount(scope),
    queryFn: async () => {
      const res = await apiClient.get<{ count: number }>("/notifications/unread-count", {
        // `all` is the server's default; sending it would only add a parameter the
        // request does not need.
        params: scope === "inbox" ? { scope } : undefined,
      });
      return res.data.count;
    },
    staleTime: STALE.standard,
    refetchOnWindowFocus: true,
  });
}

/**
 * The dropdown's list — the INBOX view (newest-first, server-ordered): undismissed rows
 * from the last {@link NOTIFICATION_INBOX_WINDOW_DAYS} days. Only fetched while the
 * panel is open, and kept fresh (staleTime 0) so re-opening always reflects the latest
 * state.
 *
 * The window and the dismissal filter are applied by the SERVER, not here. Fetching the
 * whole feed and hiding most of it would grow the payload for as long as a user's
 * history grows, to render a panel that never shows more than a page.
 */
export function useNotifications(enabled: boolean) {
  return useQuery({
    queryKey: queryKeys.notifications.list(),
    queryFn: async () => {
      const res = await apiClient.get<{ data: NotificationDto[] }>("/notifications", {
        params: { scope: "inbox" },
      });
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
      const row = findCachedNotification(qc, id);
      patchCachedRead(qc, id, true);
      if (row?.read === false) {
        bumpUnreadCount(qc, "all", -1);
        // The bell's badge only moves for a row the bell would have shown. Reading an
        // aged-out row on History must not decrement a count that never included it.
        if (isInInbox(row)) bumpUnreadCount(qc, "inbox", -1);
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
 * Still not a "dismiss". Dismissing is now a real mutation — {@link useDismissNotification}
 * — and it is a separate one on purpose: the 2026-07-29 PO decision said an archive
 * would have to be a new field rather than an overloaded `read_at`, and that is exactly
 * how it shipped. This endpoint changes read state and nothing else.
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
      const row = findCachedNotification(qc, id);
      patchCachedRead(qc, id, false);
      if (row?.read === true) {
        bumpUnreadCount(qc, "all", 1);
        if (isInInbox(row)) bumpUnreadCount(qc, "inbox", 1);
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
 * Dismiss one notification — take it off the bell dropdown.
 *
 * The row leaves the list the instant the button is pressed and the request goes out
 * behind it; on failure `onError` puts every cache back exactly as it was, so a
 * dropped connection returns the row rather than silently swallowing it.
 *
 * Dismissing also READS the row, matching the server, which is why this touches the
 * badges at all: a dismissed notification is gone from the panel, so a badge that kept
 * counting it would be counting something the reader can no longer reach.
 *
 * Both counts move, not just the bell's. The row is leaving the inbox, but it is also
 * becoming read, and `all` counts unread rows wherever they live.
 */
export function useDismissNotification() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => apiClient.patch(`/notifications/${id}/dismiss`),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: queryKeys.notifications.all });
      const snapshot = snapshotCaches(qc);

      // Read BEFORE the patch, for the same reason the read mutations do: the patch
      // below flips the flags this decision depends on, so a repeated click would
      // otherwise decrement twice.
      const row = findCachedNotification(qc, id);
      patchCachedDismissed(qc, id);
      if (row?.read === false) {
        bumpUnreadCount(qc, "all", -1);
        if (isInInbox(row)) bumpUnreadCount(qc, "inbox", -1);
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
      // Both badges, and unconditionally rather than through `bumpUnreadCount`: after
      // this there is nothing unread under ANY scope, so zero is the true value even
      // for a count that had not loaded yet.
      qc.setQueryData<number>(queryKeys.notifications.unreadCount("all"), 0);
      qc.setQueryData<number>(queryKeys.notifications.unreadCount("inbox"), 0);
      return snapshot;
    },
    onError: (_err, _vars, snapshot) => {
      if (snapshot) restoreCaches(qc, snapshot);
    },
    onSettled: () => qc.invalidateQueries({ queryKey: queryKeys.notifications.all }),
  });
}
