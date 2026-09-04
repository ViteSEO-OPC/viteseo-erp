"use client";

import { useEffect, useState } from "react";
import { X } from "lucide-react";

import { cn } from "@/lib/utils";
import { useAuth } from "@/core/auth-context";
import { Sidebar } from "@/core/components/sidebar";
import { TopBar } from "@/core/components/top-bar";
import { NotificationProvider } from "@/core/notifications-context";
import { usePersistentState } from "@/core/use-persistent-state";
import type { NavEntry, ShellVariant } from "@/core/nav";

/**
 * The platform-standard frame every portal renders inside: dark sidebar +
 * top bar + scrollable main area. One responsive contract for all systems:
 *
 *   - `lg`+ (1024px): a static sidebar column, user-collapsible to a 64px
 *     icon rail (persisted per user).
 *   - below `lg`: the sidebar is an off-canvas drawer opened by the top bar's
 *     hamburger; it closes on navigation, Escape, backdrop tap or its ✕.
 *
 * Anything positioned against the sidebar from outside this tree reads the
 * `--shell-sidebar-w` CSS variable this shell maintains on <html> (today:
 * the modal offset in `components/ui/dialog.tsx` and the CRM's) — the
 * variable, not a hardcoded breakpoint+width pair, is the contract.
 */
export function AppShell({
  variant,
  children,
  rightSlot,
  canSwitchView,
  onSwitchView,
  avatar,
  navItems,
  navBadges,
  searchSlot,
  profileHrefOverride,
}: {
  variant: ShellVariant;
  children: React.ReactNode;
  /** Optional portal-specific header actions (e.g. Sprintly's task bell) shown in the top bar. */
  rightSlot?: React.ReactNode;
  /** TeamOra's HR ⇄ employee view switch, surfaced in the header UserMenu. */
  canSwitchView?: boolean;
  onSwitchView?: () => void;
  /** The signed-in user's face for the UserMenu, when the portal has one. */
  avatar?: React.ReactNode;
  /** Dynamic nav (Sprintly builds its own per render) — overrides navByVariant. */
  navItems?: NavEntry[];
  /** Portal-supplied badge counts by href (e.g. Sprintly's pending approvals). */
  navBadges?: Record<string, number>;
  /** A portal-owned search rendered in the header's search position. */
  searchSlot?: React.ReactNode;
  /** Role-dependent profile destination — overrides profileHref[variant]. */
  profileHrefOverride?: string | null;
}) {
  const { user } = useAuth();
  const [mobileOpen, setMobileOpen] = useState(false);
  // Desktop icon-rail preference, per user. Null key until auth resolves so a
  // shared browser never leaks one account's preference into another.
  const [collapsed, setCollapsed] = usePersistentState<boolean>(
    user ? `sidebar-collapsed:${user.id}` : null,
    false,
  );

  useEffect(() => {
    if (!mobileOpen) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setMobileOpen(false);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [mobileOpen]);

  // Publish the sidebar's current width so portal-external fixed elements
  // (modals) can centre on the CONTENT area, not the viewport. The `lg:`
  // media query on the consumer side handles "no sidebar below lg".
  useEffect(() => {
    document.documentElement.style.setProperty(
      "--shell-sidebar-w",
      collapsed ? "4rem" : "16rem",
    );
    return () => {
      document.documentElement.style.removeProperty("--shell-sidebar-w");
    };
  }, [collapsed]);

  // Navigating from a drawer link should close the drawer behind you. Handled
  // by delegation rather than an effect on `pathname`: the nav links live
  // inside `Sidebar`, so there is no per-link handler to hook, and closing in
  // an effect would be a cascading setState. Anchors close it; the group
  // expand/collapse buttons deliberately don't.
  const closeIfNavigating = (e: React.MouseEvent<HTMLDivElement>) => {
    if ((e.target as HTMLElement).closest("a")) setMobileOpen(false);
  };

  return (
    // The HR feed is TeamOra's; every portal mounts the provider (the shared
    // TopBar/Sidebar always read the context) but only TeamOra polls it.
    <NotificationProvider enabled={variant === "hr" || variant === "employee"}>
      {/* Pinned to the viewport so the document itself never scrolls — only `main`
          does. (fixed inset-0 = a guaranteed frame at the VISIBLE viewport size
          regardless of the body height chain; min-w-0/min-h-0 let wide/tall
          content scroll inside `main` instead of pushing the whole page.) */}
      <div className="fixed inset-0 flex overflow-hidden">
        {/* Desktop: the static column. */}
        <div className="hidden lg:flex">
          <Sidebar
            variant={variant}
            collapsed={collapsed}
            onToggleCollapse={() => setCollapsed((c) => !c)}
            items={navItems}
            badges={navBadges}
          />
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
          id="app-mobile-nav"
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
          {/* No `collapsed`/`onToggleCollapse`: the drawer is already a
              deliberate reveal, so a rail inside it would be a second, useless
              state to manage. */}
          <Sidebar variant={variant} items={navItems} badges={navBadges} />
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
          <TopBar
            variant={variant}
            rightSlot={rightSlot}
            onOpenNav={() => setMobileOpen(true)}
            navOpen={mobileOpen}
            canSwitchView={canSwitchView}
            onSwitchView={onSwitchView}
            avatar={avatar}
            searchSlot={searchSlot}
            profileHrefOverride={profileHrefOverride}
          />
          <main className="min-h-0 flex-1 overflow-y-auto bg-background">{children}</main>
        </div>
      </div>
    </NotificationProvider>
  );
}
