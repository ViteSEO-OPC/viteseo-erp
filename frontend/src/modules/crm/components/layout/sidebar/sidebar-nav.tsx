"use client";

import { usePathname } from "next/navigation";
import {
  LayoutDashboard,
  Users,
  Clock,
  CalendarCheck,
  Target,
  MapPin,
  History,
  Settings,
  BarChart3,
  type LucideIcon,
} from "lucide-react";
import { SidebarNavItem } from "@/modules/crm/components/layout/sidebar/sidebar-nav-item";
import { useSidebarBadges } from "@/modules/crm/components/layout/sidebar/use-sidebar-badges";
import { useDashboardKpis } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import type { Role } from "@/modules/crm/lib/rbac";

const NAV_ITEMS: {
  label: string;
  icon: LucideIcon;
  href: string;
  minRole?: Role;
  allowedRoles?: readonly Role[];
}[] = [
  // One Dashboard menu / route; the page content is role-dependent (BD Dashboard vs
  // Management Dashboard for Admin/SA) — see app/(dashboard)/dashboard/page.tsx.
  { label: "Dashboard", icon: LayoutDashboard, href: "/zerem/dashboard", minRole: "crm_bd" },
  { label: "Leads", icon: Users, href: "/zerem/leads", minRole: "crm_bd" },
  // Nearby Clients — territory/location surface. Data foundation live; interactive map deferred
  // until Lead Enrichment + geocoding supply coordinates. Target-area management is Admin-only.
  { label: "Nearby Clients", icon: MapPin, href: "/zerem/nearby-clients", minRole: "crm_bd" },
  // Outreach: badge = real count of pending "Follow-ups Pending" items (the shared
  // `isPendingOutreachEvent` definition over the Planner query — see useSidebarBadges). No badge when
  // the count is zero.
  { label: "Outreach", icon: Clock, href: "/zerem/follow-ups", minRole: "crm_bd" },
  // Planner (personal BD workspace) — first consumer of the generic `allowedRoles` allow-list:
  // shown ONLY to Business Development Managers, hidden from Admin/Super Admin. Badge = real count of
  // actionable (dated, not-completed) Planner items for the current user; no badge when zero.
  { label: "Planner", icon: CalendarCheck, href: "/zerem/planner", allowedRoles: ["crm_bd"] },
  // Reports & Performance — Management analytics (Admin/Super Admin only). Reuses the My Performance
  // widgets with an All-Reports / per-BD scope selector.
  { label: "Reports & Performance", icon: BarChart3, href: "/zerem/reports", minRole: "crm_admin" },
  // My Performance — BD-exclusive personal surface; ownership-scoped server-side. Admin/SA use
  // Reports & Performance above (not this), so it is role-locked to BDs.
  { label: "My Performance", icon: Target, href: "/zerem/my-performance", allowedRoles: ["crm_bd"] },
  { label: "Activity Logs", icon: History, href: "/zerem/activity-logs", minRole: "crm_bd" },
  // "Settings" resolves per role: BDs get their self-service profile (/profile, role-exclusive like
  // Planner); Admin/Super Admin get the admin Settings module (/settings). Mutually exclusive, so a
  // user sees exactly one "Settings" entry.
  { label: "Settings", icon: Settings, href: "/zerem/profile", allowedRoles: ["crm_bd"] },
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
  // Standardized with the other badges: show the real lead count, but render nothing while loading or
  // when it is zero (no placeholder "0" badge).
  const leadsCount =
    !kpisLoading && leadsTotalRaw !== undefined ? Number(leadsTotalRaw) : undefined;
  const leadsBadge = leadsCount && leadsCount > 0 ? leadsCount : undefined;

  // Outreach + Planner badges — real counts from the shared Planner query (no new endpoint/logic),
  // undefined while loading or when zero so no placeholder badge is shown. See useSidebarBadges.
  const badges = useSidebarBadges();

  // One place that maps a nav item to its real, backend-derived badge count (undefined ⇒ no badge).
  const badgeFor = (href: string): number | undefined => {
    if (href === "/zerem/leads") return leadsBadge;
    if (href === "/zerem/follow-ups") return badges.outreach;
    if (href === "/zerem/planner") return badges.planner;
    return undefined;
  };

  // Role-aware: render only the pages the current role may access (no empty gaps). Honors both
  // the `minRole` (inherit-upward) and `allowedRoles` (exact allow-list) strategies.
  const visibleItems = NAV_ITEMS.filter((item) => canAccess(item));

  return (
    <nav aria-label="Primary" className="flex flex-col gap-0.5 px-2">
      {visibleItems.map((item) => (
        <SidebarNavItem
          key={item.href}
          {...item}
          badgeCount={badgeFor(item.href)}
          active={pathname === item.href || pathname.startsWith(item.href + "/")}
          collapsed={collapsed}
        />
      ))}
    </nav>
  );
}
