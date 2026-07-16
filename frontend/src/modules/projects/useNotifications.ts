"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/core/auth/AuthProvider";
import projectsApi from "./api";
import { fromResource, type AppNotification } from "./notifications";

export const notificationKeys = {
  all: ["notifications"] as const,
};

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
      const page = await projectsApi.listNotifications();
      return page.data.map(fromResource);
    },
    enabled: isAuthenticated,
  });
}

export function useMarkNotificationRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.markNotificationRead(id),
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
    mutationFn: () => projectsApi.markAllNotificationsRead(),
    onSuccess: () => {
      qc.setQueryData<AppNotification[]>(notificationKeys.all, (prev) =>
        prev?.map((n) => ({ ...n, unread: false })),
      );
    },
  });
}
