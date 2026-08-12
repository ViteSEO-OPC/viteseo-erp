import apiClient from "@/core/api/client";
import type { Paginated } from "@/core/types";
import type { NotificationDto } from "@/modules/projects/types/notification";

export async function getNotifications(
  params?: Record<string, unknown>,
): Promise<Paginated<NotificationDto>> {
  const { data } = await apiClient.get<Paginated<NotificationDto>>(
    "/projects/notifications",
    { params: { per_page: 30, ...params } },
  );
  return data;
}

export async function getUnreadCount(): Promise<number> {
  const { data } = await apiClient.get<{ unread_count: number }>(
    "/projects/notifications/unread-count",
  );
  return data.unread_count;
}

export async function markNotificationRead(id: string): Promise<void> {
  await apiClient.patch(`/projects/notifications/${id}/read`);
}

export async function markAllNotificationsRead(): Promise<void> {
  await apiClient.patch("/projects/notifications/read-all");
}
