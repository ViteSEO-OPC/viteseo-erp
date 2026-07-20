"use client";

import { useEffect, useState } from "react";
import { usePathname } from "next/navigation";
import { Menu, X, Zap } from "lucide-react";

import { cn } from "@/lib/utils";
import { Sidebar } from "@/core/components/sidebar";
import { TopBar } from "@/core/components/top-bar";
import { NotificationProvider } from "@/core/notifications-context";
import type { ShellVariant } from "@/core/nav";

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
  const pathname = usePathname();

  // Navigating from a drawer link should close the drawer behind you.
  useEffect(() => {
    setMobileOpen(false);
  }, [pathname]);

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
          {/* The hamburger bar. Separate from `TopBar` because that component is
              shared core — this adds the control without forking it. */}
          <div className="flex h-12 shrink-0 items-center gap-2.5 border-b border-border bg-card px-4 lg:hidden">
            <button
              type="button"
              onClick={() => setMobileOpen(true)}
              aria-label="Open navigation"
              aria-expanded={mobileOpen}
              aria-controls="teamora-mobile-nav"
              className="-ml-1.5 flex size-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            >
              <Menu className="size-5" />
            </button>
            <span className="flex size-6 items-center justify-center rounded-md bg-brand text-brand-foreground">
              <Zap className="size-4" fill="currentColor" />
            </span>
            <span className="font-bold text-foreground">TeamOra</span>
          </div>

          <TopBar variant={variant} />
          <main className="min-h-0 flex-1 overflow-y-auto bg-background">
            {children}
          </main>
        </div>
      </div>
    </NotificationProvider>
  );
}
