"use client";

import Link from "next/link";
import { Avatar } from "@/core/ui";
import type { AppNotification } from "../../notifications";

/**
 * Bell dropdown popover — matches the Figma "notification-dropdown".
 *
 * Anchored to the bell's right edge, so its width is what has to give on a
 * narrow screen: a flat 380px pushed the panel off the left of a phone viewport
 * (heading clipped, avatars cut) with nothing to scroll to reach it. It now
 * caps at the viewport minus the topbar's gutters.
 */
export function NotificationDropdown({
  items,
  onMarkAllRead,
  onSelect,
  onClose,
}: {
  items: AppNotification[];
  onMarkAllRead: () => void;
  /** Clicking a notification — opens its task (if any) and marks it read. */
  onSelect: (n: AppNotification) => void;
  onClose: () => void;
}) {
  return (
    <div className="absolute right-0 top-[calc(100%+8px)] z-20 w-[min(380px,calc(100vw_-_2rem))] overflow-hidden rounded-2xl border border-line bg-surface shadow-xl">
      <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-4 sm:px-5">
        <h3 className="text-base font-bold text-ink">Notifications</h3>
        <button
          type="button"
          onClick={onMarkAllRead}
          className="shrink-0 text-xs font-bold text-brand hover:text-brand-hover"
        >
          Mark all as read
        </button>
      </div>

      <div className="max-h-[min(380px,55vh)] divide-y divide-line overflow-y-auto">
        {items.length === 0 && (
          <p className="px-4 py-8 text-center text-sm text-ink-soft sm:px-5">
            No notifications yet.
          </p>
        )}
        {items.slice(0, 5).map((n) => (
          <button
            key={n.id}
            type="button"
            onClick={() => onSelect(n)}
            className="flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-black/[0.03] sm:px-5"
          >
            <span className="shrink-0">
              <Avatar name={n.actor} size={36} />
            </span>
            <div className="min-w-0 flex-1">
              <p className="text-sm leading-snug break-words text-ink">
                <span className="font-bold">{n.actor}</span> {n.message}
              </p>
              <p className="mt-0.5 text-xs text-ink-soft">{n.time}</p>
            </div>
            {n.unread && (
              <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand" />
            )}
          </button>
        ))}
      </div>

      <Link
        href="/sprintly/notifications"
        onClick={onClose}
        className="block border-t border-line py-3 text-center text-sm font-bold text-brand hover:bg-brand/5"
      >
        View all notifications
      </Link>
    </div>
  );
}
