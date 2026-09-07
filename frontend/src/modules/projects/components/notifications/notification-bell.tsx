"use client";

import { useEffect, useRef, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { BellIcon } from "@/core/ui";
import { NotificationDropdown } from "@/modules/projects/components/notifications/notification-dropdown";
import { useOpenTask } from "@/modules/projects/hooks/use-task-modal";
import type { AppNotification } from "@/modules/projects/types/notification";
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotifications,
} from "@/modules/projects/hooks/use-notifications";

/**
 * Sprintly's own header bell + dropdown.
 *
 * This used to live inside `core/layout/Topbar` — one module's feed embedded in
 * a platform file, which is also why Sprintly needed a second top bar at all.
 * It now travels with the module and is handed to the shared AppShell through
 * `rightSlot`, which is what suppresses the shell's built-in HR bell (a feed
 * Sprintly has nothing to do with).
 */
export function NotificationBell() {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const { data: items = [] } = useNotifications();
  const markAllRead = useMarkAllNotificationsRead();
  const markRead = useMarkNotificationRead();
  const openTask = useOpenTask();
  const router = useRouter();
  const pathname = usePathname();

  const unread = items.filter((n) => n.unread).length;

  const handleSelect = (n: AppNotification) => {
    if (n.unread) markRead.mutate(n.id);
    setOpen(false);
    if (!n.taskId) return;
    // The task modal is mounted under /sprintly/* — open it in place there,
    // otherwise route to a Sprintly page carrying the ?task= param.
    if (pathname.startsWith("/sprintly")) {
      openTask(n.taskId);
    } else {
      router.push(`/sprintly/notifications?task=${n.taskId}`);
    }
  };

  // Close the dropdown on outside click.
  useEffect(() => {
    if (!open) return;
    const onClick = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, [open]);

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="relative flex size-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        aria-label="Notifications"
        aria-expanded={open}
      >
        <BellIcon className="h-5 w-5" />
        {unread > 0 && (
          <span className="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-bold text-white">
            {unread}
          </span>
        )}
      </button>
      {open && (
        <NotificationDropdown
          items={items}
          onMarkAllRead={() => markAllRead.mutate()}
          onSelect={handleSelect}
          onClose={() => setOpen(false)}
        />
      )}
    </div>
  );
}
