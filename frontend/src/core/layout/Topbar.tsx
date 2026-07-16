"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { Menu, LayoutGrid } from "lucide-react";
import { BellIcon } from "@/core/ui";
import { useAuth } from "@/core/auth-context";
import { getAccessibleModules } from "@/core/access";
import { useSidebar } from "@/core/layout/SidebarContext";
import { NotificationDropdown } from "@/modules/projects/components/NotificationDropdown";
import { useOpenTask } from "@/modules/projects/components/task-modal/useTaskModal";
import type { AppNotification } from "@/modules/projects/notifications";
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotifications,
} from "@/modules/projects/useNotifications";

/**
 * App topbar — thin sticky bar across the top of the main content area with a
 * right-aligned notification bell + unread count and dropdown, matching the
 * Figma screens.
 */
export function Topbar() {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const { openMobile, mobileOpen } = useSidebar();
  const { data: items = [] } = useNotifications();
  const markAllRead = useMarkAllNotificationsRead();
  const markRead = useMarkNotificationRead();
  const openTask = useOpenTask();
  const router = useRouter();
  const pathname = usePathname();
  const { roles, permissions } = useAuth();

  const unread = items.filter((n) => n.unread).length;
  // Multi-system users get a "Switch app" jump back to the launcher.
  const canSwitchApp =
    getAccessibleModules(roles as unknown as string[], permissions).length > 1;

  const handleSelect = (n: AppNotification) => {
    if (n.unread) markRead.mutate(n.id);
    setOpen(false);
    if (!n.taskId) return;
    // The task modal is mounted under /projects/* — open it in place there,
    // otherwise route to a projects page carrying the ?task= param.
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
      if (ref.current && !ref.current.contains(e.target as Node))
        setOpen(false);
    };
    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, [open]);

  return (
    <header className="sticky top-0 z-10 border-b border-line bg-canvas/80 backdrop-blur">
      <div className="flex items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
        <div className="flex items-center gap-3">
          {/* Hamburger — opens the off-canvas drawer on mobile (< lg) */}
          <button
            type="button"
            onClick={openMobile}
            className="text-ink transition-colors hover:text-ink-soft lg:hidden"
            aria-label="Open navigation menu"
            aria-expanded={mobileOpen}
            aria-controls="app-sidebar"
          >
            <Menu className="h-6 w-6" />
          </button>

          {/* Switch app — back to the launcher (multi-system users only). */}
          {canSwitchApp && (
            <Link
              href="/"
              className="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-ink-soft transition-colors hover:bg-line hover:text-ink"
            >
              <LayoutGrid className="h-4 w-4" />
              <span className="hidden sm:inline">Switch app</span>
            </Link>
          )}
        </div>

        <div className="relative" ref={ref}>
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            className="relative text-ink transition-colors hover:text-ink-soft"
            aria-label="Notifications"
          >
            <BellIcon className="h-6 w-6" />
            {unread > 0 && (
              <span className="absolute -right-1.5 -top-1.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-danger px-1 text-[10px] font-bold text-white">
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
      </div>
    </header>
  );
}
