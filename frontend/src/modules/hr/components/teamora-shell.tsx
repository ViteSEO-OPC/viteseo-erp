"use client";

import { useEffect, useState } from "react";
import { X } from "lucide-react";

import { cn } from "@/lib/utils";
import { Sidebar } from "@/core/components/sidebar";
import { TopBar } from "@/core/components/top-bar";
import { NotificationProvider } from "@/core/notifications-context";
import type { ShellVariant } from "@/core/nav";
import { TeamoraMobileTopBar } from "@/modules/hr/components/teamora-mobile-top-bar";

/**
 * TeamOra's responsive frame — an HR-local replacement for `core/AppShell`.
 *
 * `AppShell` renders the sidebar as a permanent 256px column, which leaves a
 * 375px phone ~119px for content. This wraps the *unmodified* core `Sidebar`
 * and `TopBar` instead of forking them: below `lg` the sidebar becomes an
 * off-canvas drawer opened by a hamburger, at `lg`+ it is the static column
 * exactly as before.
 *
 * It lives in `modules/hr/` rather than `core/` so the shared shell (which
 * Sprintly/Zerem also render) stays untouched. The drawer mechanics mirror
 * `core/layout/Sidebar.tsx`, the Projects shell that already does this —
 * worth consolidating the two once the platform merge lands.
 */
export function TeamoraShell({
  variant,
  children,
}: {
  variant: ShellVariant;
  children: React.ReactNode;
}) {
  const [mobileOpen, setMobileOpen] = useState(false);

  // Navigating from a drawer link should close the drawer behind you. Handled
  // by delegation rather than an effect on `pathname`: the nav links live
  // inside core's `Sidebar`, so there is no per-link handler to hook, and
  // closing in an effect would be a cascading setState. Anchors close it;
  // the group expand/collapse buttons deliberately don't.
  const closeIfNavigating = (e: React.MouseEvent<HTMLDivElement>) => {
    if ((e.target as HTMLElement).closest("a")) setMobileOpen(false);
  };

  useEffect(() => {
    if (!mobileOpen) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setMobileOpen(false);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [mobileOpen]);

  return (
    <NotificationProvider>
      {/* Pinned to the viewport so the document itself never scrolls — only
          `main` does. (min-w-0 lets wide content scroll inside `main` rather
          than pushing the whole page sideways.) */}
      <div className="fixed inset-0 flex overflow-hidden">
        {/* Desktop: the static column, unchanged from AppShell. */}
        <div className="hidden lg:flex">
          <Sidebar variant={variant} />
        </div>

        {/* Mobile: backdrop + off-canvas drawer. */}
        {mobileOpen && (
          <div
            className="fixed inset-0 z-40 bg-black/50 lg:hidden"
            onClick={() => setMobileOpen(false)}
            aria-hidden="true"
          />
        )}
        <div
          id="teamora-mobile-nav"
          role="dialog"
          aria-modal="true"
          aria-label="Navigation menu"
          onClick={closeIfNavigating}
          className={cn(
            "fixed inset-y-0 left-0 z-50 transition-transform duration-300 ease-in-out lg:hidden",
            // `invisible` (not just the off-screen transform) so the drawer's
            // links leave the tab order while it is closed.
            mobileOpen ? "visible translate-x-0" : "invisible -translate-x-full",
          )}
        >
          <Sidebar variant={variant} />
          <button
            type="button"
            onClick={() => setMobileOpen(false)}
            aria-label="Close navigation"
            className="absolute top-5 right-3 flex size-8 items-center justify-center rounded-lg text-sidebar-foreground transition-colors hover:bg-sidebar-accent hover:text-white"
          >
            <X className="size-5" />
          </button>
        </div>

        <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
          {/* Two bars, one at a time: the HR-owned mobile bar below `lg` (it
              carries the hamburger, and a notification dropdown that fits a
              320px screen), core's shared bar from `lg` up. */}
          <TeamoraMobileTopBar
            variant={variant}
            onOpenNav={() => setMobileOpen(true)}
          />
          <div className="hidden lg:block">
            <TopBar variant={variant} />
          </div>

          <main className="min-h-0 flex-1 overflow-y-auto bg-background">
            {children}
          </main>
        </div>
      </div>
    </NotificationProvider>
  );
}
