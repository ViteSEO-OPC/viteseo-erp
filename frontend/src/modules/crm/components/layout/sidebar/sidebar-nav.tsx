"use client";

import { usePathname } from "next/navigation";
import {
  LayoutDashboard,
  Users,
  Clock,
  CalendarCheck,
  BarChart3,
  Target,
  MapPin,
  History,
  Settings,
  type LucideIcon,
} from "lucide-react";
import { SidebarNavItem } from "@/modules/crm/components/layout/sidebar/sidebar-nav-item";
import { useDashboardKpis } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import type { Role } from "@/modules/crm/lib/rbac";

const NAV_ITEMS: {
  label: string;
  icon: LucideIcon;
  href: string;
  minRole?: Role;
  allowedRoles?: readonly Role[];
  badgeCount?: number;
}[] = [
  // One Dashboard menu / route; the page content is role-dependent (BD Dashboard vs
  // Management Dashboard for Admin/SA) — see app/(dashboard)/dashboard/page.tsx.
  { label: "Dashboard", icon: LayoutDashboard, href: "/zerem/dashboard", minRole: "crm_bd" },
  { label: "Leads", icon: Users, href: "/zerem/leads", minRole: "crm_bd" },
  // Nearby Clients — territory/location surface. Data foundation live; interactive map deferred
  // until Lead Enrichment + geocoding supply coordinates. Target-area management is Admin-only.
  { label: "Nearby Clients", icon: MapPin, href: "/zerem/nearby-clients", minRole: "crm_bd" },
  // Outreach: no backend follow-ups/outreach count exists yet (follow-ups feature is mock,
  // no endpoint). Intentionally left static for Sprint 6 — do not fabricate a source.
  { label: "Outreach", icon: Clock, href: "/zerem/follow-ups", minRole: "crm_bd", badgeCount: 12 },
  // Planner (personal BD workspace) — first consumer of the generic `allowedRoles` allow-list:
  // shown ONLY to Business Development Managers, hidden from Admin/Super Admin.
  { label: "Planner", icon: CalendarCheck, href: "/zerem/planner", allowedRoles: ["crm_bd"] },
  { label: "Reports", icon: BarChart3, href: "/zerem/reports", minRole: "crm_admin" },
  // My Performance — the BD-facing personal surface (S1). Visible to every role; content is
  // ownership-scoped so each BD sees only their own data (BD-PERF-FE-ARCH-REVIEW OQ-FE-1).
  { label: "My Performance", icon: Target, href: "/zerem/my-performance", minRole: "crm_bd" },
  { label: "Activity Logs", icon: History, href: "/zerem/activity-logs", minRole: "crm_bd" },
  { label: "Settings", icon: Settings, href: "/zerem/settings", minRole: "crm_admin" },
];

type SidebarNavProps = {
  collapsed?: boolean;
};

export function SidebarNav({ collapsed }: SidebarNavProps) {
  const pathname = usePathname();
  const { canAccess } = usePermissions();

  // Reuse the shared Dashboard KPI query (same queryKey the Dashboard + Leads pages already use)
  // for the real Leads total — no new endpoint, no badge-specific request; served from cache when
  // that query is already resolved. Hidden until resolved so no placeholder number is shown.
  const { data: kpis, isLoading: kpisLoading } = useDashboardKpis();
  const leadsTotalRaw = kpis?.find((k) => k.id === "total-leads")?.value;
  const leadsBadge =
    !kpisLoading && leadsTotalRaw !== undefined ? Number(leadsTotalRaw) : undefined;

  // Role-aware: render only the pages the current role may access (no empty gaps). Honors both
  // the `minRole` (inherit-upward) and `allowedRoles` (exact allow-list) strategies.
  const visibleItems = NAV_ITEMS.filter((item) => canAccess(item));

  return (
    <nav aria-label="Primary" className="flex flex-col gap-0.5 px-2">
      {visibleItems.map((item) => {
        const badgeCount = item.href === "/zerem/leads" ? leadsBadge : item.badgeCount;
        return (
          <SidebarNavItem
            key={item.href}
            {...item}
            badgeCount={badgeCount}
            active={pathname === item.href || pathname.startsWith(item.href + "/")}
            collapsed={collapsed}
          />
        );
      })}
    </nav>
  );
}
