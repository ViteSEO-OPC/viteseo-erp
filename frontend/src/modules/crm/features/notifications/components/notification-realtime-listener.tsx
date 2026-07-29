"use client";

import { useNotificationRealtime } from "@/modules/crm/features/notifications/hooks/use-notification-realtime";

/**
 * Renders nothing; mounts the notification socket listener once for the whole CRM.
 *
 * A component rather than a hook call in the layout so it sits INSIDE
 * <RealtimeProvider> — the socket is established by then, and it never runs on the
 * layout's pre-auth or role-redirect renders, which mount no realtime layer. Same
 * shape as LeadRealtimeListener; each feature owns its own listener rather than one
 * component accumulating every feature's subscriptions.
 *
 * Mounted once, in the shell, because the bell lives in the shell: a BD should be
 * told about a notification wherever they are in the CRM, not only on the screen
 * that happens to display notifications.
 */
export function NotificationRealtimeListener(): null {
  useNotificationRealtime();
  return null;
}
