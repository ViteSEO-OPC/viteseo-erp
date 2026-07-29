"use client";

import { useEffect, useRef, type ReactNode } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/core/auth-context";
import {
  fromResource,
  type AppNotification,
  type NotificationDto,
} from "@/modules/projects/notifications";
import { notificationKeys } from "@/modules/projects/useNotifications";
import { getEcho, teardownEcho } from "./echo";

/**
 * What actually arrives on `user.{id}`: a module's own notification resource plus
 * the platform event's `module` tag. Kept separate from NotificationDto because
 * that type describes the REST shape, which needs no discriminator — the REST
 * endpoints are already per-module.
 */
type BroadcastNotification = NotificationDto & { module?: string };

/**
 * Owns the WebSocket lifecycle: connect Echo while authenticated, subscribe the
 * private `user.{id}` channel for live notifications, and tear the socket down
 * on logout.
 *
 * Mounted by the CRM section layout (src/app/zerem/layout.tsx), NOT the root
 * layout — realtime is CRM-scoped for now, so no socket is opened for HRIS,
 * Sprintly or any other app. It still relies on the root layout for both of its
 * contexts: AuthProvider (to read the user) and QueryProvider (to patch the
 * cache). Sprintly's own `useProjectChannel` calls getEcho() directly and is
 * unaffected by where this is mounted.
 *
 * Reads the platform auth context directly rather than the Projects module's
 * auth shim: this is platform infrastructure and must not depend on a module.
 * `authChecked` / `isAuthenticated` are derived here from `loading` / `user`,
 * which is exactly what that shim was doing.
 */
export function RealtimeProvider({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  const qc = useQueryClient();
  const connectedOnce = useRef(false);

  const authChecked = !loading;
  const isAuthenticated = !!user;
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
    //
    // `user.{id}` is a SHARED stream: a user has one notification channel however
    // many modules they work in, and every module broadcasts the same platform
    // NotificationCreated event on it. The `module` tag says whose payload this is,
    // so this handler keeps only Projects' and ignores the rest — without it, a
    // notification from another module would be mapped by fromResource() and
    // written straight into the Projects cache.
    const userChannel = echo.private(`user.${userId}`);
    userChannel.listen(".NotificationCreated", (dto: BroadcastNotification) => {
      if (dto.module !== "projects") return;

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

  // Because this is mounted per-app rather than at the root, unmounting means the
  // user has navigated out of the CRM. The effect above only leaves the channel;
  // this closes the transport too, so an idle socket never follows them into
  // HRIS or Sprintly. Re-entering the CRM lazily rebuilds it via getEcho().
  useEffect(() => teardownEcho, []);

  return <>{children}</>;
}
