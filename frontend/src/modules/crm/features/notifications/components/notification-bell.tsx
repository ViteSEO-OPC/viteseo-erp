"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { ArrowRight, Bell, X } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from "@/modules/crm/components/ui/dropdown-menu";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { NotificationItem } from "@/modules/crm/features/notifications/components/notification-item";
import {
  useDismissNotification,
  useMarkAllRead,
  useMarkRead,
  useNotifications,
  useUnreadCount,
} from "@/modules/crm/features/notifications/hooks/use-notifications";
import {
  NOTIFICATION_INBOX_WINDOW_DAYS,
  type NotificationDto,
} from "@/modules/crm/features/notifications/types/notification-types";

/**
 * Header Notification Center. The bell mirrors the ThemeToggle icon-button styling (no header
 * redesign) and carries the real unread count. Opening it fetches the newest-first list; clicking a
 * notification marks it read and deep-links to the related CRM record. All data is the current user's
 * own (server-scoped). Theme-token driven, so light + dark are both correct automatically.
 *
 * A WORKING INBOX, NOT AN ARCHIVE (management feedback 2026-07-31). The panel shows the
 * last {@link NOTIFICATION_INBOX_WINDOW_DAYS} days minus whatever the reader has
 * dismissed; the History page behind "View all" is still the complete record and is
 * unchanged. Both narrowings are the server's (`scope=inbox`) — nothing is fetched here
 * only to be hidden — and the badge is counted under the same scope, so the number on
 * the bell always describes the rows inside it.
 */
export function NotificationBell({ className }: { className?: string }) {
  const [open, setOpen] = useState(false);
  const router = useRouter();

  const { data: unreadCount = 0 } = useUnreadCount("inbox");
  const { data: notifications, isLoading, isError } = useNotifications(open);
  const markRead = useMarkRead();
  const markAll = useMarkAllRead();
  const dismiss = useDismissNotification();

  const handleSelect = (notification: NotificationDto) => {
    if (!notification.read) markRead.mutate(notification.id);
    if (notification.link) {
      setOpen(false);
      router.push(notification.link);
    }
  };

  return (
    <DropdownMenu open={open} onOpenChange={setOpen}>
      <DropdownMenuTrigger
        className={cn(
          "relative flex h-9 w-9 items-center justify-center rounded-lg text-muted-foreground transition-colors",
          "hover:bg-muted hover:text-secondary-foreground",
          "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
          // Open state. `bg-accent` is the design system's own "this popup is open"
          // surface — the same token dropdown-menu.tsx puts on a SubTrigger via
          // `data-popup-open` — replacing `bg-muted`, which was the identical value
          // `hover:` uses and so made an open dropdown look like a pointer resting
          // on it. Keyed off `data-popup-open` (Base UI's attribute) rather than
          // `aria-expanded`, to match how the rest of the kit expresses this.
          //
          // The icon carries the active colour too, because the surface alone cannot:
          // in the dark theme `--accent` and `--muted` are the SAME value (#22262d),
          // so background-only would collapse back into the hover look there. Teal is
          // this feature's existing emphasis colour — the same pair already used by
          // "Mark all as read" and "View all notifications" below.
          "data-popup-open:bg-accent data-popup-open:text-teal-700",
          "dark:data-popup-open:text-teal-400",
          className,
        )}
        aria-label={
          unreadCount > 0 ? `Notifications (${unreadCount} unread)` : "Notifications"
        }
      >
        <Bell className="h-[18px] w-[18px]" />
        {unreadCount > 0 && (
          <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-bold leading-none text-primary-foreground">
            {unreadCount > 99 ? "99+" : unreadCount}
          </span>
        )}
      </DropdownMenuTrigger>

      {/* A flex COLUMN, so the header and footer are fixed and only the list
          scrolls. The panel is capped at the popup's `--available-height`, and with
          a plain block layout the footer simply fell off the bottom of a short
          viewport — measured at 577px tall: content 524px into a 507px panel, with
          the retention note the clipped part. Pinning the chrome and letting the
          middle absorb the shortfall keeps the note visible at any height, which
          matters because it is the explanation for rows the reader cannot find. */}
      <DropdownMenuContent
        align="end"
        sideOffset={8}
        className="flex w-[calc(100vw-1.5rem)] max-w-[380px] flex-col overflow-hidden p-0 sm:w-[380px]"
      >
        <div className="flex shrink-0 items-center justify-between border-b border-border-subtle px-4 py-3">
          <span className="text-sm font-semibold text-foreground">Notifications</span>
          {unreadCount > 0 && (
            <button
              type="button"
              onClick={() => markAll.mutate()}
              disabled={markAll.isPending}
              className="text-xs font-medium text-teal-700 transition-colors hover:underline disabled:opacity-50 dark:text-teal-400"
            >
              Mark all as read
            </button>
          )}
        </div>

        {/* Sized by its CONTENT up to 420px, and allowed to shrink below that when
            the panel hits the popup's available height — the default `flex: 0 1 auto`,
            not `flex-1`. `flex-1` sets `flex-basis: 0%`, which in a column whose own
            height comes from its content is degenerate: the list asks for zero, gets
            zero, and the panel collapses to a single scrolling row (measured: a 207px
            panel showing one notification of seven).

            `min-h-0` is the other half. A flex item's default `min-height: auto`
            refuses to shrink below its content, so without it a full list would still
            push the footer off the bottom instead of scrolling inside itself. */}
        <div className="min-h-0 max-h-[420px] overflow-y-auto p-1">
          {isLoading ? (
            <p className="px-3 py-10 text-center text-sm text-muted-foreground">Loading…</p>
          ) : isError ? (
            <p className="px-3 py-10 text-center text-sm text-muted-foreground">
              Couldn&rsquo;t load notifications.
            </p>
          ) : !notifications || notifications.length === 0 ? (
            <EmptyState message="You’re all caught up" className="border-0 py-10" />
          ) : (
            notifications.map((notification) => (
              // Same row geometry as the History page: NotificationItem is itself a
              // <button>, so the dismiss control is a SIBLING rather than nested inside
              // it — a button within a button is invalid HTML and swallows the inner
              // click in some browsers. Keeping the two surfaces structurally identical
              // is also why a row looks the same in both.
              <div key={notification.id} className="flex items-start gap-1">
                <div className="min-w-0 flex-1">
                  <NotificationItem notification={notification} onSelect={handleSelect} />
                </div>
                <button
                  type="button"
                  onClick={() => dismiss.mutate(notification.id)}
                  aria-label={`Dismiss "${notification.title}"`}
                  title="Dismiss"
                  className={cn(
                    // Its own column, so it can never crowd or overlap the unread dot
                    // that sits at the end of the title line inside the card.
                    "mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-colors",
                    // Present but quiet: visible enough to be discoverable on touch,
                    // where a hover-only control simply does not exist, and muted enough
                    // that a full panel does not read as a column of X's.
                    "text-muted-foreground/60 hover:bg-muted hover:text-foreground",
                    "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                  )}
                >
                  <X className="h-3.5 w-3.5" />
                </button>
              </div>
            ))
          )}
        </div>

        {/* The way out to the full history. The list above shows only the recent,
            undismissed page — browsing, searching and filtering live on the History
            page rather than growing this panel. */}
        <div className="shrink-0 border-t border-border-subtle p-1">
          <button
            type="button"
            onClick={() => {
              setOpen(false);
              router.push("/zerem/notifications");
            }}
            className={cn(
              "flex w-full items-center justify-center gap-1 rounded-lg px-3 py-2 text-xs font-medium",
              "text-teal-700 transition-colors hover:bg-muted dark:text-teal-400",
              "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring",
            )}
          >
            View all notifications
            <ArrowRight className="h-3.5 w-3.5" />
          </button>

          {/* Says out loud what the window does, so a reader who remembers a
              notification and cannot find it here knows where it went rather than
              assuming the bell lost it. Placed under the link on purpose: it explains
              this list, and the destination of that link is the answer. */}
          <p className="px-3 pb-1 text-center text-[11px] leading-snug text-muted-foreground">
            Notifications older than {NOTIFICATION_INBOX_WINDOW_DAYS} days are automatically
            removed from this list.
          </p>
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
