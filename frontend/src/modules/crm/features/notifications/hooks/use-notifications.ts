"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { NotificationDto } from "@/modules/crm/features/notifications/types/notification-types";

/**
 * Unread count for the header badge. No background polling (approved 2026-07-16): freshness comes from
 * refetch-on-window-focus + invalidation of `queryKeys.notifications.all` after mark-read / mark-all.
 * Real-time push (a notification arriving while the tab stays focused) is a later Reverb/WebSockets
 * enhancement — until then a background-created notification surfaces on the next focus change.
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
      const prevList = qc.getQueryData<NotificationDto[]>(queryKeys.notifications.list());
      const prevCount = qc.getQueryData<number>(queryKeys.notifications.unreadCount());
      const wasUnread = prevList?.find((n) => n.id === id)?.read === false;
      qc.setQueryData<NotificationDto[]>(queryKeys.notifications.list(), (old) =>
        old?.map((n) => (n.id === id ? { ...n, read: true } : n)),
      );
      if (wasUnread && typeof prevCount === "number") {
        qc.setQueryData<number>(queryKeys.notifications.unreadCount(), Math.max(0, prevCount - 1));
      }
      return { prevList, prevCount };
    },
    onError: (_err, _id, ctx) => {
      if (ctx?.prevList) qc.setQueryData(queryKeys.notifications.list(), ctx.prevList);
      if (ctx?.prevCount !== undefined) {
        qc.setQueryData(queryKeys.notifications.unreadCount(), ctx.prevCount);
      }
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
      const prevList = qc.getQueryData<NotificationDto[]>(queryKeys.notifications.list());
      const prevCount = qc.getQueryData<number>(queryKeys.notifications.unreadCount());
      // Only a row that is currently READ changes the count — re-marking an already
      // unread row is a no-op the badge must not double-count.
      const wasRead = prevList?.find((n) => n.id === id)?.read === true;
      qc.setQueryData<NotificationDto[]>(queryKeys.notifications.list(), (old) =>
        old?.map((n) => (n.id === id ? { ...n, read: false, read_at: null } : n)),
      );
      if (wasRead && typeof prevCount === "number") {
        qc.setQueryData<number>(queryKeys.notifications.unreadCount(), prevCount + 1);
      }
      return { prevList, prevCount };
    },
    onError: (_err, _id, ctx) => {
      if (ctx?.prevList) qc.setQueryData(queryKeys.notifications.list(), ctx.prevList);
      if (ctx?.prevCount !== undefined) {
        qc.setQueryData(queryKeys.notifications.unreadCount(), ctx.prevCount);
      }
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
      const prevList = qc.getQueryData<NotificationDto[]>(queryKeys.notifications.list());
      const prevCount = qc.getQueryData<number>(queryKeys.notifications.unreadCount());
      qc.setQueryData<NotificationDto[]>(queryKeys.notifications.list(), (old) =>
        old?.map((n) => ({ ...n, read: true })),
      );
      qc.setQueryData<number>(queryKeys.notifications.unreadCount(), 0);
      return { prevList, prevCount };
    },
    onError: (_err, _vars, ctx) => {
      if (ctx?.prevList) qc.setQueryData(queryKeys.notifications.list(), ctx.prevList);
      if (ctx?.prevCount !== undefined) {
        qc.setQueryData(queryKeys.notifications.unreadCount(), ctx.prevCount);
      }
    },
    onSettled: () => qc.invalidateQueries({ queryKey: queryKeys.notifications.all }),
  });
}
