"use client";

import { useNotificationRealtime } from "@/modules/crm/features/notifications/hooks/use-notification-realtime";

/**
 * Mounts the notification socket listener once for the whole CRM, and speaks what it
 * receives.
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
 *
 * The live region renders nothing visible. `polite` rather than `assertive`: a
 * notification is worth mentioning, never worth cutting off what the user is
 * currently being read. Keyed on the sequence so two notifications with identical
 * text still announce twice — a live region whose text does not change is silent.
 */
export function NotificationRealtimeListener() {
  const announcement = useNotificationRealtime();

  return (
    <div aria-live="polite" aria-atomic="true" className="sr-only">
      {announcement && <span key={announcement.seq}>{announcement.text}</span>}
    </div>
  );
}
