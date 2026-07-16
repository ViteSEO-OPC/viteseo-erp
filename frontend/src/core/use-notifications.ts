"use client";

import { useQuery } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type { NotificationsPayload } from "@/core/types/notifications";

async function getNotifications(): Promise<NotificationsPayload> {
  const res = await apiClient.get("/hr/me/notifications");
  return res.data.data;
}

// Polls every 15s (+ refetch on window focus) so the bell and nav badges stay
// live without wiring every mutation. Shared by TopBar + Sidebar.
export function useNotifications() {
  return useQuery({
    queryKey: ["hr", "me", "notifications"],
    queryFn: getNotifications,
    refetchInterval: 15000,
  });
}
