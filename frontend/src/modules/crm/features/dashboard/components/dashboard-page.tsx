"use client";

import { useEffect, useRef } from "react";
import { useRouter } from "next/navigation";
import { KpiStatRow } from "@/modules/crm/features/dashboard/components/kpi-stat-row";
import { LeadPriorityOverview } from "@/modules/crm/features/dashboard/components/lead-priority-overview";
import { RecentLeadsTable } from "@/modules/crm/features/dashboard/components/recent-leads-table";
import { RecentActivityPanel } from "@/modules/crm/features/dashboard/components/recent-activity-panel";
import { DashboardSplitRow } from "@/modules/crm/features/dashboard/components/dashboard-split-row";
import { useHeaderSearch } from "@/modules/crm/providers/header-search-provider";

export function DashboardPage() {
  const router = useRouter();
  // Keep the router in a ref so the register-effect runs once (useRouter() returns
  // a new reference each render, which would otherwise re-run the effect and wipe
  // the typed value on every keystroke).
  const routerRef = useRef(router);
  useEffect(() => {
    routerRef.current = router;
  }, [router]);

  const { setRegistered, setOnSubmit } = useHeaderSearch();

  // Reuse the global header search: on Enter, navigate to the Leads page with the
  // query so the existing Leads search flow handles filtering. No duplicate logic.
  // (The Leads page seeds/clears the header value itself, so we don't reset it here.)
  useEffect(() => {
    setRegistered(true);
    setOnSubmit((value) => {
      const query = value.trim();
      routerRef.current.push(query ? `/leads?search=${encodeURIComponent(query)}` : "/leads");
    });
    return () => {
      setRegistered(false);
      setOnSubmit(undefined);
    };
  }, [setRegistered, setOnSubmit]);

  return (
    <div className="flex flex-col gap-8">
      <KpiStatRow />
      <LeadPriorityOverview />
      <DashboardSplitRow
        left={<RecentLeadsTable />}
        right={<RecentActivityPanel />}
      />
    </div>
  );
}
