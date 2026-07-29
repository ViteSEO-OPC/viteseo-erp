"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { ArrowRight, Bell } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from "@/modules/crm/components/ui/dropdown-menu";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { NotificationItem } from "@/modules/crm/features/notifications/components/notification-item";
import {
  useMarkAllRead,
  useMarkRead,
  useNotifications,
  useUnreadCount,
} from "@/modules/crm/features/notifications/hooks/use-notifications";
import type { NotificationDto } from "@/modules/crm/features/notifications/types/notification-types";

/**
 * Header Notification Center. The bell mirrors the ThemeToggle icon-button styling (no header
 * redesign) and carries the real unread count. Opening it fetches the newest-first list; clicking a
 * notification marks it read and deep-links to the related CRM record. All data is the current user's
 * own (server-scoped). Theme-token driven, so light + dark are both correct automatically.
 */
export function NotificationBell({ className }: { className?: string }) {
  const [open, setOpen] = useState(false);
  const router = useRouter();

  const { data: unreadCount = 0 } = useUnreadCount();
  const { data: notifications, isLoading, isError } = useNotifications(open);
  const markRead = useMarkRead();
  const markAll = useMarkAllRead();

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
          "aria-expanded:bg-muted aria-expanded:text-secondary-foreground",
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

      <DropdownMenuContent
        align="end"
        sideOffset={8}
        className="w-[calc(100vw-1.5rem)] max-w-[380px] p-0 sm:w-[380px]"
      >
        <div className="flex items-center justify-between border-b border-border-subtle px-4 py-3">
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

        <div className="max-h-[min(70vh,420px)] overflow-y-auto p-1">
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
              <NotificationItem
                key={notification.id}
                notification={notification}
                onSelect={handleSelect}
              />
            ))
          )}
        </div>

        {/* The only change to this dropdown in Slice 4: a way out to the full history.
            The list above still shows just the most recent page — browsing, searching
            and filtering live on the History page rather than growing this panel. */}
        <div className="border-t border-border-subtle p-1">
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
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
