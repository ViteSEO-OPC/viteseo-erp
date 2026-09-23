"use client";

import { useEffect, useState, type ReactNode } from "react";
import { cn } from "@/modules/crm/lib/utils";
import { useAuth } from "@/core/auth-context";
import { usePersistentState } from "@/core/use-persistent-state";
import { Sidebar } from "@/modules/crm/components/layout/sidebar/sidebar";
import { PageHeader } from "@/modules/crm/components/layout/topbar/page-header";
import { Sheet, SheetContent } from "@/modules/crm/components/ui/sheet";
import { CopilotWidget } from "@/modules/crm/features/copilot/components/copilot-widget";
import { HeaderSearchProvider } from "@/modules/crm/providers/header-search-provider";
import { usePathname } from "next/navigation";

const PAGE_TITLES: Record<string, string> = {
  "/zerem/dashboard": "Dashboard",
  "/zerem/leads": "Leads",
  "/zerem/inbox": "Conversation Inbox",
  "/zerem/nearby-clients": "Nearby Clients",
  "/zerem/follow-ups": "Outreach",
  "/zerem/planner/history": "Planner History",
  "/zerem/planner": "Planner",
  "/zerem/calendar": "Planner",
  "/zerem/reports": "Reports & Performance",
  "/zerem/my-performance": "My Performance",
  "/zerem/activity-logs": "Activity Logs",
  "/zerem/notifications": "Notifications",
  "/zerem/settings": "Settings",
};

type AppShellProps = {
  children: ReactNode;
};

/**
 * Zerem's frame. It keeps the CRM's own chrome — the page title, the lead
 * search, the CRM notification feed — but now follows the platform's shell
 * contract so the portal behaves like the others:
 *
 *   - `lg`+ : a static sidebar column, user-collapsible to a 64px icon rail,
 *     the preference persisted PER USER (keyed on the user id, so a shared
 *     browser can't leak one account's choice into another's session).
 *   - below `lg`: the off-canvas drawer, opened by the header's hamburger.
 *     This used to break at `md`, leaving a band where the rail was forced on
 *     with no way to read a label.
 *   - `--shell-sidebar-w` on <html>, so anything positioned against the
 *     sidebar from outside this tree reads the live width rather than
 *     hardcoding a breakpoint (the CRM's dialog does).
 */
export function AppShell({ children }: AppShellProps) {
  const { user } = useAuth();
  const [mobileOpen, setMobileOpen] = useState(false);
  // Null key until auth resolves, so nothing is read or written against a
  // user that isn't known yet.
  const [collapsed, setCollapsed] = usePersistentState<boolean>(
    user ? `sidebar-collapsed:${user.id}` : null,
    false,
  );
  const pathname = usePathname();
  // Longest matching prefix, not the first declared: a nested route may have its own title while
  // still sitting under a titled parent (/zerem/planner/history under /zerem/planner), and a
  // first-match scan would silently show the parent's title depending on key order.
  const title =
    Object.entries(PAGE_TITLES)
      .filter(([path]) => pathname.startsWith(path))
      .sort(([a], [b]) => b.length - a.length)[0]?.[1] ?? "Dashboard";
  const isLeadsPage = pathname === "/zerem/leads" || pathname.startsWith("/zerem/leads/");

  // The same variable AppShell (core) publishes, with the same two widths — a
  // consumer must not have to know WHICH shell framed the page.
  useEffect(() => {
    document.documentElement.style.setProperty(
      "--shell-sidebar-w",
      collapsed ? "4rem" : "16rem",
    );
    return () => {
      document.documentElement.style.removeProperty("--shell-sidebar-w");
    };
  }, [collapsed]);

  return (
    <HeaderSearchProvider>
      <div className="flex h-screen overflow-hidden">
      {/* Desktop: the static column, collapsible to the rail. */}
      <div className="hidden lg:flex">
        <Sidebar
          collapsed={collapsed}
          onToggleCollapse={() => setCollapsed((c) => !c)}
        />
      </div>

      {/* Mobile off-canvas overlay. 16rem matches the platform drawer; the
          drawer is already a deliberate reveal, so it never renders the rail. */}
      <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
        <SheetContent side="left" className="w-64 p-0">
          {/* Navigating from a drawer link closes the drawer behind you.
              Delegated rather than per-link: the links live inside SidebarNav,
              so there is no handler to hook, and closing in an effect on
              `pathname` would be a cascading setState. */}
          <div
            className="h-full"
            onClick={(e) => {
              if ((e.target as HTMLElement).closest("a")) setMobileOpen(false);
            }}
          >
            <Sidebar overlay />
          </div>
        </SheetContent>
      </Sheet>

      {/* Content region */}
      <div className="flex flex-1 flex-col overflow-hidden">
        <PageHeader
          onMenuClick={() => setMobileOpen(true)}
          menuOpen={mobileOpen}
        />
        {/* pb-24 reserves the strip the Copilot FAB floats in. The button is `fixed bottom-6` and
            48px tall, so it covers the lowest ~72px of the viewport; with the old pb-8 (32px) the
            last element on any page — most visibly the Files & Downloads rows on mobile, where the
            rail stacks last — ended up underneath it with its actions unreachable. Scroll padding
            is what fixes this: the FAB is fixed to the viewport, so only extra room at the end of
            the scroll can clear it. */}
        <main
          className={cn(
            "flex-1 overflow-y-auto bg-background px-4 pb-24 lg:px-6",
            isLeadsPage ? "pt-6" : "pt-8",
          )}
        >
          {/* The page title, at the top of the page rather than inside the header bar.
              Every other portal puts it here (see core/components/page-header.tsx, which HR and
              Sprintly pages render themselves); Zerem kept it in the header, and that is what
              pushed its search over to the right and made this one module read differently from
              the rest. Rendered by the shell rather than by each page because no Zerem page has
              ever owned its own <h1> — the title has always arrived as a prop from the route. */}
          <h1 className="font-primary text-subheading md:text-card-title lg:text-page-title mb-6 font-bold text-card-foreground">
            {title}
          </h1>
          {children}
        </main>
      </div>
      <CopilotWidget />
      </div>
    </HeaderSearchProvider>
  );
}
