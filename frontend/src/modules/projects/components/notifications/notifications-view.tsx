"use client";

import { Avatar, Card } from "@/core/ui";
import { NOTIFICATION_GROUPS } from "@/modules/projects/lib/notifications";
import type { AppNotification } from "@/modules/projects/types/notification";
import { useOpenTask } from "@/modules/projects/hooks/use-task-modal";
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotifications,
} from "@/modules/projects/hooks/use-notifications";
import {
  SkeletonList,
} from "@/modules/projects/components/shared/skeletons";

/**
 * Notifications page — date-grouped activity feed, from the Figma
 * "notifications-page". Backed by the real notifications API + live updates.
 */
export function NotificationsView() {
  const { data: items = [], isLoading } = useNotifications();
  const markAll = useMarkAllNotificationsRead();
  const markRead = useMarkNotificationRead();
  const openTask = useOpenTask();
  const markAllRead = () => markAll.mutate();

  const handleSelect = (n: AppNotification) => {
    if (n.unread) markRead.mutate(n.id);
    if (n.taskId) openTask(n.taskId);
  };

  return (
    <div className="px-4 py-6 sm:px-6 lg:px-8">
      {/* Header */}
      <div className="flex items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-display text-ink sm:text-3xl">Notifications</h1>
        <button
          type="button"
          onClick={markAllRead}
          className="text-sm font-bold text-brand-strong hover:text-brand-strong-hover"
        >
          Mark all as read
        </button>
      </div>

      {isLoading ? (
        <SkeletonList rows={6} className="mt-6" />
      ) : items.length === 0 ? (
        <Card className="mt-6 p-10 text-center">
          <p className="text-ink-soft">You&apos;re all caught up — no notifications.</p>
        </Card>
      ) : null}

      <div className="mt-6 space-y-8">
        {NOTIFICATION_GROUPS.map((group) => {
          const groupItems = items.filter((n) => n.group === group);
          if (groupItems.length === 0) return null;
          return (
            <div key={group}>
              <p className="mb-3 text-xs font-bold uppercase tracking-wide text-ink-soft">
                {group}
              </p>
              <Card className="divide-y divide-line rounded-xl p-0">
                {groupItems.map((n) => (
                  <button
                    key={n.id}
                    type="button"
                    onClick={() => handleSelect(n)}
                    className="flex w-full items-start gap-3 px-5 py-4 text-left transition-colors hover:bg-black/[0.03]"
                  >
                    <Avatar name={n.actor} size={40} />
                    <div className="min-w-0 flex-1">
                      <p className="text-sm leading-snug text-ink">
                        <span className="font-bold">{n.actor}</span> {n.message}
                      </p>
                      <p className="mt-0.5 text-xs text-ink-soft">{n.time}</p>
                    </div>
                    {n.unread && (
                      <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand" />
                    )}
                  </button>
                ))}
              </Card>
            </div>
          );
        })}
      </div>
    </div>
  );
}
