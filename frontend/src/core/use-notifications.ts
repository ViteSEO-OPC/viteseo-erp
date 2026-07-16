"use client";

import { useQuery } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type { NotificationsPayload } from "@/core/types/notifications";

async function getNotifications(
  limit: number,
  offset: number,
): Promise<NotificationsPayload> {
  const res = await apiClient.get("/hr/me/notifications", {
    params: { limit, offset },
  });
  return res.data.data;
}

// Polls every 15s (+ refetch on window focus) so the bell and nav badges stay
// live without wiring every mutation. Shared by TopBar + Sidebar.
export function useNotifications(limit: number, offset: number) {
  return useQuery({
    queryKey: ["hr", "me", "notifications", { limit, offset }],
    queryFn: () => getNotifications(limit, offset),
    refetchInterval: 15000,
  });
}
