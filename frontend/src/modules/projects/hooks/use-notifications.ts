"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/core/auth/AuthProvider";
import {
  getNotifications,
  markAllNotificationsRead,
  markNotificationRead,
} from "@/modules/projects/api/notifications";
import { notificationKeys } from "@/modules/projects/lib/query-keys";
import { fromResource } from "@/modules/projects/lib/notifications";
import type { AppNotification } from "@/modules/projects/types/notification";
import { notifyMutationError } from "@/modules/projects/lib/api-error";
import { STALE } from "@/modules/projects/lib/query-freshness";

/**
 * The authenticated user's notifications, mapped to the UI shape. The cache
 * holds `AppNotification[]` (mapping happens in `queryFn`) so the live
 * NotificationCreated handler can prepend a mapped item directly.
 */
export function useNotifications() {
  const { isAuthenticated } = useAuth();
  return useQuery({
    queryKey: notificationKeys.all,
    queryFn: async (): Promise<AppNotification[]> => {
      const page = await getNotifications();
      return page.data.map(fromResource);
    },
    enabled: isAuthenticated,
    // Arrives over the socket (useProjectNotifications writes straight into
    // this cache), so a refetch is a backstop, not the delivery mechanism.
    staleTime: STALE.standard,
  });
}

export function useMarkNotificationRead() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't mark that as read"),
    mutationFn: (id: string) => markNotificationRead(id),
    onSuccess: (_data, id) => {
      qc.setQueryData<AppNotification[]>(notificationKeys.all, (prev) =>
        prev?.map((n) => (n.id === id ? { ...n, unread: false } : n)),
      );
    },
  });
}

export function useMarkAllNotificationsRead() {
  const qc = useQueryClient();
  return useMutation({
    onError: notifyMutationError("Couldn't mark all as read"),
    mutationFn: () => markAllNotificationsRead(),
    onSuccess: () => {
      qc.setQueryData<AppNotification[]>(notificationKeys.all, (prev) =>
        prev?.map((n) => ({ ...n, unread: false })),
      );
    },
  });
}
