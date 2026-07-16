"use client";

import Link from "next/link";
import { Avatar } from "@/core/ui";
import type { AppNotification } from "../notifications";

/** Bell dropdown popover — matches the Figma "notification-dropdown". */
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
    <div className="absolute right-0 top-[calc(100%+8px)] z-20 w-[380px] overflow-hidden rounded-2xl border border-line bg-surface shadow-xl">
      <div className="flex items-center justify-between border-b border-line px-5 py-4">
        <h3 className="text-base font-bold text-ink">Notifications</h3>
        <button
          type="button"
          onClick={onMarkAllRead}
          className="text-xs font-bold text-brand hover:text-brand-hover"
        >
          Mark all as read
        </button>
      </div>

      <div className="max-h-[380px] divide-y divide-line overflow-y-auto">
        {items.length === 0 && (
          <p className="px-5 py-8 text-center text-sm text-ink-soft">
            No notifications yet.
          </p>
        )}
        {items.slice(0, 5).map((n) => (
          <button
            key={n.id}
            type="button"
            onClick={() => onSelect(n)}
            className="flex w-full items-start gap-3 px-5 py-3 text-left transition-colors hover:bg-black/[0.03]"
          >
            <Avatar name={n.actor} size={36} />
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
