"use client";

import { useEffect, useRef } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { getEcho } from "@/core/realtime/echo";
import { useAuth } from "@/modules/crm/providers/auth-provider";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { NotificationDto } from "@/modules/crm/features/notifications/types/notification-types";

/** What arrives on `user.{id}`: a module's notification resource plus the platform tag. */
type BroadcastNotification = NotificationDto & { module?: string };

const NOTIFICATION_CREATED = ".NotificationCreated";

/**
 * Live notifications for the CRM bell.
 *
 * Patches the caches the SHIPPED bell and dropdown already read — it adds no UI and
 * replaces no hook. `useUnreadCount` and `useNotifications` are unchanged; this only
 * removes their dependence on a window-focus change to notice a new notification,
 * which is the limitation their own comments described.
 *
 * CHANNEL OWNERSHIP. `user.{id}` is shared: RealtimeProvider owns its lifecycle and
 * Projects listens on it too. This attaches and detaches ONLY its own handler —
 * echo.leave() here would take platform notifications down with it — and filters on
 * `module` so another module's payload is never mistaken for a CRM notification.
 */
export function useNotificationRealtime(): void {
  const { user } = useAuth();
  const qc = useQueryClient();
  const userId = user?.id;

  // Distinguishes the first connect from a re-connect: on the first, the REST
  // fetches already reflect current state and a refetch would be pure noise.
  const connectedOnce = useRef(false);

  useEffect(() => {
    if (userId == null) return;

    // Captured once — the cleanup must not call getEcho() again, since the CRM
    // shell may already have torn the singleton down by the time it runs.
    const echo = getEcho();
    if (!echo) return;

    const channel = echo.private(`user.${userId}`);

    const onCreated = (payload: BroadcastNotification) => {
      if (payload.module !== "crm") return;

      const countKey = queryKeys.notifications.unreadCount();
      const listKey = queryKeys.notifications.list();

      // WHY THE IN-FLIGHT CHECK. A local write cannot defend itself against a
      // request that is already on the wire: that request was sent before this
      // notification existed, and when it lands query-core calls `setData` on the
      // response unconditionally (no comparison against a manual write), so it
      // overwrites whatever we put here. The shipped mutations avoid this by
      // cancelling first; the same is not right here, because cancelling would
      // also throw away the reconnect backfill's refetch and leave `prev + 1`
      // stacked on a base we already know to be stale.
      //
      // So: when nothing is in flight, increment locally — instant, no request,
      // and the value cannot be clobbered. When something IS in flight, do not
      // guess at all; invalidate, which cancels the stale request and refetches
      // the real number (`invalidateQueries` defaults to `cancelRefetch: true`,
      // so this replaces that request rather than queueing behind it). The extra
      // request happens only on the narrow overlap, never on the common path.
      if (qc.getQueryState(countKey)?.fetchStatus === "fetching") {
        qc.invalidateQueries({ queryKey: countKey });
      } else {
        // Badge first: it is the part the user sees without opening anything.
        // Left alone when the count has never loaded — the bell's own query will
        // fetch the true number.
        qc.setQueryData<number>(countKey, (prev) =>
          typeof prev === "number" ? prev + 1 : prev,
        );
      }

      // The list carries the identical hazard: an in-flight response predates
      // this row and would drop it straight back out of an open panel.
      if (qc.getQueryState(listKey)?.fetchStatus === "fetching") {
        qc.invalidateQueries({ queryKey: listKey });
      } else {
        // Only patched if already cached. `useNotifications` runs only while the
        // panel is open, so seeding a cache here would leave a one-item list
        // waiting for a panel that has never been opened.
        qc.setQueryData<NotificationDto[]>(listKey, (prev) => {
          if (!prev) return prev;
          // De-duplicate by id: a redelivered frame, or a socket event racing a
          // refetch that already included this row, must not double it.
          if (prev.some((n) => n.id === payload.id)) return prev;
          return [payload, ...prev];
        });
      }
    };

    channel.listen(NOTIFICATION_CREATED, onCreated);

    // Reconnect backfill. Anything broadcast while the socket was down was missed
    // outright — without this the badge silently under-counts until the next focus
    // change, which is exactly the staleness realtime is here to remove.
    const pusher = (
      echo.connector as unknown as {
        pusher?: {
          connection?: {
            bind?: (e: string, cb: () => void) => void;
            unbind?: (e: string, cb: () => void) => void;
          };
        };
      }
    ).pusher;

    const onConnected = () => {
      if (connectedOnce.current) {
        qc.invalidateQueries({ queryKey: queryKeys.notifications.all });
      }
      connectedOnce.current = true;
    };
    pusher?.connection?.bind?.("connected", onConnected);

    return () => {
      channel.stopListening(NOTIFICATION_CREATED, onCreated);
      pusher?.connection?.unbind?.("connected", onConnected);
    };
  }, [userId, qc]);
}
