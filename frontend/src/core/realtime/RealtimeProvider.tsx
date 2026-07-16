"use client";

import { useEffect, useRef, type ReactNode } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/core/auth/AuthProvider";
import {
  fromResource,
  type AppNotification,
  type NotificationDto,
} from "@/modules/projects/notifications";
import { notificationKeys } from "@/modules/projects/useNotifications";
import { getEcho, teardownEcho } from "./echo";

/**
 * Owns the global WebSocket lifecycle: connect Echo while authenticated,
 * subscribe the private `user.{id}` channel for live notifications, and tear the
 * socket down on logout. Mounted inside AuthProvider so it can read the user.
 */
export function RealtimeProvider({ children }: { children: ReactNode }) {
  const { user, isAuthenticated, authChecked } = useAuth();
  const qc = useQueryClient();
  const connectedOnce = useRef(false);

  const userId = user?.id;

  useEffect(() => {
    // Not (yet) authenticated → ensure the socket is down.
    if (!authChecked || !isAuthenticated || userId == null) {
      teardownEcho();
      connectedOnce.current = false;
      return;
    }

    const echo = getEcho();
    if (!echo) return;

    // Live notifications on the user's private channel.
    const userChannel = echo.private(`user.${userId}`);
    userChannel.listen(".NotificationCreated", (dto: NotificationDto) => {
      const item = fromResource(dto);
      qc.setQueryData<AppNotification[]>(notificationKeys.all, (prev) => {
        if (!prev) return [item];
        if (prev.some((n) => n.id === item.id)) return prev;
        return [item, ...prev];
      });
    });

    // Reconnect backfill: after a dropped connection re-establishes, refetch
    // active queries so any events missed while offline are picked up. Skip the
    // very first connect (the initial REST fetches already cover current state).
    const pusher = (
      echo.connector as unknown as {
        pusher?: { connection?: { bind?: (e: string, cb: () => void) => void; unbind?: (e: string, cb: () => void) => void } };
      }
    ).pusher;
    const onConnected = () => {
      if (connectedOnce.current) {
        qc.invalidateQueries({ queryKey: notificationKeys.all });
        qc.invalidateQueries({ queryKey: ["projects"] });
      }
      connectedOnce.current = true;
    };
    pusher?.connection?.bind?.("connected", onConnected);

    return () => {
      echo.leave(`user.${userId}`);
      pusher?.connection?.unbind?.("connected", onConnected);
    };
  }, [authChecked, isAuthenticated, userId, qc]);

  return <>{children}</>;
}
