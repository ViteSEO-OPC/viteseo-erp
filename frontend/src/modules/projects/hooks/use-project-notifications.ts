"use client";

import { useQueryClient } from "@tanstack/react-query";
import {
  useRealtimeEvent,
  useRealtimeReconnect,
} from "@/core/realtime/use-realtime";
import { useAuth } from "@/core/auth-context";
import { fromResource } from "@/modules/projects/lib/notifications";
import type {
  AppNotification,
  NotificationDto,
} from "@/modules/projects/types/notification";
import {
  notificationKeys,
  projectKeys,
} from "@/modules/projects/lib/query-keys";

/**
 * Live Projects notifications on the user's shared `user.{id}` channel.
 *
 * Extracted from RealtimeProvider unchanged in behaviour. It lived there because the
 * provider happened to be the thing holding the channel; that is precisely what made
 * the provider's cleanup able to take the CRM's handler down with it. Now that the
 * manager owns the channel and hands out per-listener subscriptions, this is just
 * another registered callback — it cannot disturb the CRM's, and the CRM's cannot
 * disturb it.
 *
 * `user.{id}` is a SHARED stream: one notification channel per person however many
 * modules they work in, with every module broadcasting the same platform
 * `NotificationCreated` event on it. The `module` tag says whose payload this is, so
 * this handler keeps only Projects' — without that filter a CRM notification would be
 * mapped by fromResource() and written straight into the Projects cache.
 */

/** What arrives on the wire: a module's notification resource plus the platform tag. */
type BroadcastNotification = NotificationDto & { module?: string };

export function useProjectNotifications(): void {
  const { user } = useAuth();
  const qc = useQueryClient();
  const userId = user?.id;

  useRealtimeEvent<BroadcastNotification>(
    userId == null ? null : `user.${userId}`,
    ".NotificationCreated",
    (dto) => {
      if (dto.module !== "projects") return;

      const item = fromResource(dto);
      qc.setQueryData<AppNotification[]>(notificationKeys.all, (prev) => {
        if (!prev) return [item];
        // De-duplicate: a redelivered frame, or a socket event racing a refetch that
        // already included this row, must not double it.
        if (prev.some((n) => n.id === item.id)) return prev;
        return [item, ...prev];
      });
    },
  );

  // Reconnect backfill, carried over from RealtimeProvider verbatim: anything
  // broadcast while the socket was down was missed outright. The "skip the first
  // connect" rule the old code hand-rolled with a ref now comes from the manager.
  useRealtimeReconnect(() => {
    qc.invalidateQueries({ queryKey: notificationKeys.all });
    // What a missed frame can have changed WITHOUT a project channel noticing:
    // the pool (someone claimed a task), the cross-project feed, and the
    // project list. Boards, statuses and task details are the project
    // channel's own reconnect handler to refresh — it is subscribed for every
    // project currently on screen, so repeating that here only refetched the
    // same queries twice. This used to invalidate ["projects"] outright, i.e.
    // the entire module, on every socket flap.
    qc.invalidateQueries({ queryKey: projectKeys.claimableRoot });
    qc.invalidateQueries({ queryKey: projectKeys.activityFeedRoot });
    qc.invalidateQueries({ queryKey: projectKeys.projectsRoot });
  });
}
