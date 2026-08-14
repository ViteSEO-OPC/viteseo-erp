"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { X } from "lucide-react";

import { cn } from "@/lib/utils";
import { useAuth } from "@/core/auth-context";
import { Sidebar } from "@/core/components/sidebar";
import { TopBar } from "@/core/components/top-bar";
import { NotificationProvider } from "@/core/notifications-context";
import { TeamoraMobileTopBar } from "@/modules/hr/components/teamora-mobile-top-bar";
import { useViewMode } from "@/modules/hr/components/view-mode-context";
import { EmployeeAvatar } from "@/modules/hr/ui/employee-avatar";
import { useMyEmployee } from "@/modules/hr/hooks/use-my-employee";

/**
 * TeamOra's responsive frame — an HR-local replacement for `core/AppShell`.
 *
 * `AppShell` renders the sidebar as a permanent 256px column, which leaves a
 * 375px phone ~119px for content. This wraps the shared core `Sidebar` and
 * `TopBar` instead of forking them: below `xl` the sidebar becomes an
 * off-canvas drawer opened by a hamburger, at `xl`+ it is the static column
 * exactly as before.
 *
 * The switch is `xl` (1280px), not `lg`: the sidebar costs a flat 256px, so at
 * 1024px it was taking a quarter of the window and leaving pages — the employee
 * record worst of all, since it spends another 300px on its own rail — too
 * little to lay out in. Every real laptop width (1280, 1366, 1440, 1512, 1536)
 * still gets the persistent column; the drawer covers iPad-landscape and
 * half-screen windows, which are tablet-shaped anyway.
 *
 * Anything positioned against the sidebar from outside the shell has to move
 * with this. Today that is the modal offset in `components/ui/dialog.tsx`
 * (`xl:left-64`) — keep the two breakpoints identical or modals centre on a
 * sidebar that isn't there.
 *
 * It lives in `modules/hr/` rather than `core/` so the shared shell (which
 * Sprintly/Zerem also render) stays untouched. The drawer mechanics mirror
 * `core/layout/Sidebar.tsx`, the Projects shell that already does this —
 * worth consolidating the two once the platform merge lands.
 */
export function TeamoraShell({ children }: { children: React.ReactNode }) {
  const [mobileOpen, setMobileOpen] = useState(false);
  const router = useRouter();
  const { variant, canSwitch, toggle } = useViewMode();

  // The sidebar's user card shows the viewer's own profile picture. Core's Sidebar
  // takes the rendered avatar rather than a URL — where a face comes from is ours to
  // know. Only passed when there is a photo, so accounts without one keep the plain
  // initials chip. The upload already invalidates this query, so a new picture lands
  // here without a reload.
  const { user, employee } = useAuth();
  const { data: me } = useMyEmployee({ enabled: !!employee });
  const avatar = me?.photo_url ? (
    <EmployeeAvatar
      name={me.name ?? user?.name ?? "?"}
      photoUrl={me.photo_url}
      colorSeed={me.id}
      className="size-9"
    />
  ) : undefined;

  // Navigating from a drawer link should close the drawer behind you. Handled
  // by delegation rather than an effect on `pathname`: the nav links live
  // inside core's `Sidebar`, so there is no per-link handler to hook, and
  // closing in an effect would be a cascading setState. Anchors close it;
  // the group expand/collapse buttons deliberately don't.
  const closeIfNavigating = (e: React.MouseEvent<HTMLDivElement>) => {
    if ((e.target as HTMLElement).closest("a")) setMobileOpen(false);
  };

  // The "Switch to Employee/HR view" control lives in the shared core `Sidebar`,
  // but the policy is ours: `useViewMode` decides who may switch and what
  // switching does, and the button just calls back. Always land on `/teamora` —
  // the one route both navs share — since the page you were on (say
  // `/teamora/employees`) may not exist in the portal you are switching into.
  const switchView = () => {
    toggle();
    setMobileOpen(false);
    router.push("/teamora");
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
        <div className="hidden xl:flex">
          <Sidebar
            variant={variant}
            canSwitchView={canSwitch}
            onSwitchView={switchView}
            avatar={avatar}
          />
        </div>

        {/* Mobile: backdrop + off-canvas drawer. */}
        {mobileOpen && (
          <div
            className="fixed inset-0 z-40 bg-black/50 xl:hidden"
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
            "fixed inset-y-0 left-0 z-50 transition-transform duration-300 ease-in-out xl:hidden",
            // `invisible` (not just the off-screen transform) so the drawer's
            // links leave the tab order while it is closed.
            mobileOpen ? "visible translate-x-0" : "invisible -translate-x-full",
          )}
        >
          <Sidebar
            variant={variant}
            canSwitchView={canSwitch}
            onSwitchView={switchView}
            avatar={avatar}
          />
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
          {/* Two bars, one at a time: the HR-owned mobile bar below `xl` (it
              carries the hamburger, and a notification dropdown that fits a
              320px screen), core's shared bar from `xl` up. */}
          <TeamoraMobileTopBar
            variant={variant}
            onOpenNav={() => setMobileOpen(true)}
          />
          <div className="hidden xl:block">
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
