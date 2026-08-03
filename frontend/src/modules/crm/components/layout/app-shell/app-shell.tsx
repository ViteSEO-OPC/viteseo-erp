"use client";

import { useState, type ReactNode } from "react";
import { cn } from "@/modules/crm/lib/utils";
import { Sidebar } from "@/modules/crm/components/layout/sidebar/sidebar";
import { PageHeader } from "@/modules/crm/components/layout/topbar/page-header";
import { Sheet, SheetContent } from "@/modules/crm/components/ui/sheet";
import { CopilotWidget } from "@/modules/crm/features/copilot/components/copilot-widget";
import { HeaderSearchProvider } from "@/modules/crm/providers/header-search-provider";
import { usePathname } from "next/navigation";

const PAGE_TITLES: Record<string, string> = {
  "/zerem/dashboard": "Dashboard",
  "/zerem/leads": "Leads",
  "/zerem/nearby-clients": "Nearby Clients",
  "/zerem/follow-ups": "Outreach",
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

export function AppShell({ children }: AppShellProps) {
  const [mobileOpen, setMobileOpen] = useState(false);
  const pathname = usePathname();
  const title = Object.entries(PAGE_TITLES).find(([path]) =>
    pathname.startsWith(path),
  )?.[1] ?? "Dashboard";
  const isLeadsPage = pathname === "/zerem/leads" || pathname.startsWith("/zerem/leads/");

  return (
    <HeaderSearchProvider>
      <div className="flex h-screen overflow-hidden">
      {/* Desktop full sidebar */}
      <aside className="hidden lg:flex">
        <Sidebar variant="full" />
      </aside>

      {/* Tablet icon-rail sidebar */}
      <aside className="hidden md:flex lg:hidden">
        <Sidebar variant="icon-rail" />
      </aside>

      {/* Mobile off-canvas overlay */}
      <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
        <SheetContent side="left" className="w-[280px] p-0">
          <Sidebar variant="overlay" />
        </SheetContent>
      </Sheet>

      {/* Content region */}
      <div className="flex flex-1 flex-col overflow-hidden">
        <PageHeader
          title={title}
          onMenuClick={() => setMobileOpen(true)}
        />
        <main
          className={cn(
            "flex-1 overflow-y-auto bg-background px-4 pb-8 lg:px-6",
            isLeadsPage ? "pt-6" : "pt-8",
          )}
        >
          {children}
        </main>
      </div>
      <CopilotWidget />
      </div>
    </HeaderSearchProvider>
  );
}
