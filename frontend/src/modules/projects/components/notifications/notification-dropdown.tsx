"use client";

import Link from "next/link";
import { Avatar } from "@/core/ui";
import type { AppNotification } from "@/modules/projects/types/notification";

/**
 * Bell dropdown popover — matches the Figma "notification-dropdown".
 *
 * From `sm` up it hangs off the bell's right edge. Below that it can't: the
 * bell is not the last control in the header — the theme, app-switcher and
 * account buttons sit to its right — so `right-0` puts the panel's right edge
 * ~130px in from the viewport's, and a 358px panel then started at -104px, off
 * the left of a 390px phone with no way to scroll to it. Capping the WIDTH
 * never fixed that; the anchor was the problem.
 *
 * So on phones it stops being a popover and becomes a sheet pinned to the
 * header's own gutters (`inset-x-4` matches the header's `px-4`, `top-18` clears
 * its `h-16` plus the usual 8px). The shell's header is in a `fixed inset-0`
 * layout and never scrolls, so a fixed panel stays put under it.
 *
 * 560px, not `sm`: the popover needs its 380px plus the ~156px of header
 * controls to the bell's right plus a gutter, so it fits from ~552px — and at
 * `sm` (640px) the sheet was still stretching a two-line notification across
 * 607px for no reason.
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
    <div className="fixed inset-x-4 top-18 z-20 overflow-hidden rounded-2xl border border-line bg-surface shadow-xl min-[560px]:absolute min-[560px]:inset-x-auto min-[560px]:right-0 min-[560px]:top-[calc(100%+8px)] min-[560px]:w-[380px]">
      <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-4 sm:px-5">
        <h3 className="text-base font-bold text-ink">Notifications</h3>
        <button
          type="button"
          onClick={onMarkAllRead}
          className="shrink-0 text-xs font-bold text-brand-strong hover:text-brand-strong-hover"
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
        className="block border-t border-line py-3 text-center text-sm font-bold text-brand-strong hover:bg-brand-strong/5"
      >
        View all notifications
      </Link>
    </div>
  );
}
