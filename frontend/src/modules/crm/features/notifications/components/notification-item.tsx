"use client";

import { Bell, CalendarClock, Mail, UserPlus, type LucideIcon } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";
import type { NotificationDto } from "@/modules/crm/features/notifications/types/notification-types";

// ⚠ CONVENTION (approved 2026-07-16): every notification type MUST have an explicit icon here, plus a
// meaningful title/message/deep-link in the backend NotificationResource. The `Bell` fallback below is
// a safety net only — a new notification type is not "done" until it is mapped in both places.
const ICON_BY_TYPE: Record<string, LucideIcon> = {
  inbound_reply: Mail,
  planner_reminder: CalendarClock,
  lead_assigned: UserPlus,
};

type Props = {
  notification: NotificationDto;
  onSelect: (notification: NotificationDto) => void;
};

export function NotificationItem({ notification, onSelect }: Props) {
  const Icon = ICON_BY_TYPE[notification.type] ?? Bell;
  const unread = !notification.read;

  return (
    <button
      type="button"
      onClick={() => onSelect(notification)}
      className={cn(
        "flex w-full items-start gap-3 rounded-lg px-3 py-2.5 text-left transition-colors",
        "hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring",
        unread && "bg-primary/5",
      )}
    >
      <span
        className={cn(
          "mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full",
          unread ? "bg-primary/10 text-teal-700 dark:text-teal-400" : "bg-muted text-muted-foreground",
        )}
      >
        <Icon className="h-4 w-4" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-2">
          <span className="truncate text-sm font-semibold text-foreground">{notification.title}</span>
          {unread && (
            <span
              className="ml-auto h-2 w-2 shrink-0 rounded-full bg-primary"
              aria-label="Unread"
            />
          )}
        </span>
        {notification.message && (
          <span className="mt-0.5 block truncate text-xs text-muted-foreground">
            {notification.message}
          </span>
        )}
        <span className="mt-1 block text-[11px] text-muted-foreground">
          {formatActivityTimestamp(notification.created_at)}
        </span>
      </span>
    </button>
  );
}
