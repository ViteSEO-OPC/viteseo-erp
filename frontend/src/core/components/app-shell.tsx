"use client";

import { Sidebar } from "@/core/components/sidebar";
import { TopBar } from "@/core/components/top-bar";
import { NotificationProvider } from "@/core/notifications-context";
import type { ShellVariant } from "@/core/nav";

/**
 * The persistent frame every portal page renders inside: dark sidebar + white
 * top bar + scrollable main area. `variant` picks the nav set (hr / employee).
 * NotificationProvider lives here (only mounts for authenticated portals).
 */
export function AppShell({
  variant,
  children,
}: {
  variant: ShellVariant;
  children: React.ReactNode;
}) {
  return (
    <NotificationProvider>
      {/* Pinned to the viewport so the document itself never scrolls — only `main`
          does. (fixed inset-0 = a guaranteed 100vw×100vh frame regardless of the
          body height chain; min-w-0/min-h-0 let wide/tall content scroll inside
          `main` instead of pushing the whole page.) */}
      <div className="fixed inset-0 flex overflow-hidden">
        <Sidebar variant={variant} />
        <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
          <TopBar />
          <main className="min-h-0 flex-1 overflow-y-auto bg-background">{children}</main>
        </div>
      </div>
    </NotificationProvider>
  );
}
