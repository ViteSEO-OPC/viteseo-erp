"use client";

import { useState } from "react";
import { Download } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { useExportPerformancePdf } from "@/modules/crm/features/performance/hooks/use-export-performance-pdf";
import {
  ALL_SCOPE,
  PerformanceScopeProvider,
  type PerformanceScope,
} from "@/modules/crm/features/performance/lib/performance-scope";
import { PerformanceKpiRow } from "@/modules/crm/features/performance/components/performance-kpi-row";
import { ConversionCard } from "@/modules/crm/features/performance/components/conversion-card";
import { OutreachTargetCard } from "@/modules/crm/features/performance/components/outreach-target-card";
import { FollowUpDisciplineCard } from "@/modules/crm/features/performance/components/follow-up-discipline-card";
import { MyRecentActivityCard } from "@/modules/crm/features/performance/components/my-recent-activity-card";
import { LeadAcquisitionCard } from "@/modules/crm/features/reports/components/lead-acquisition-card";
import { ReportsScopeSelector } from "@/modules/crm/features/reports/components/reports-scope-selector";
import { ManagementKpiRow } from "@/modules/crm/features/reports/components/management-kpi-row";
import { ConversionFunnelCard } from "@/modules/crm/features/reports/components/conversion-funnel-card";
import { LeadStatusMixCard } from "@/modules/crm/features/reports/components/lead-status-mix-card";
import { OutreachByChannelCard } from "@/modules/crm/features/reports/components/outreach-by-channel-card";
import { QuarterTargetsCard } from "@/modules/crm/features/reports/components/quarter-targets-card";
import { DeclineReasonCard } from "@/modules/crm/features/reports/components/decline-reason-card";
import { PendingFollowUpsCard } from "@/modules/crm/features/reports/components/pending-follow-ups-card";
import { LeadsByNicheCard } from "@/modules/crm/features/reports/components/leads-by-niche-card";

/**
 * Reports & Performance — the Management analytics surface (Admin/Super Admin only; RBAC-gated). Two
 * scopes render two DISTINCT layouts, each faithful to its own approved mockup:
 *
 *   • **A specific BD** — the BD scorecard (BD mockup): KPI row, Conversion donut, Outreach Progress,
 *     Follow-up discipline, Recent Activity, Contact trend. A personal, ownership-scoped view.
 *   • **All Reports** — the whole-team Management dashboard (Management mockup): a management KPI row,
 *     Conversion funnel + Lead status mix, Outreach by channel + Monthly acquisition, Quarter targets +
 *     Decline reasons, Pending follow-ups, Leads by niche. It does NOT reuse the BD scorecard widgets.
 *
 * Both read the same {@link PerformanceScopeProvider}, so every widget swaps its data server-side with no
 * parallel endpoints. (This is the Reports & Performance page only — the BD-facing My Performance page is
 * a separate surface and is untouched.)
 */
export function ReportsPerformancePage() {
  const [scope, setScope] = useState<PerformanceScope>(ALL_SCOPE);
  const isAllReports = scope.mode === "all";
  const { exportPerformancePdf, isExporting } = useExportPerformancePdf();

  return (
    <div className="flex flex-col gap-6">
      {/* Export follows the selected report context: a drilled BD exports that BD, All Reports
          exports the CRM-wide scope. The same hook and endpoint as My Performance — the server
          resolves and authorizes the scope from the same `user_id` param the page's queries use. */}
      <div className="flex items-center justify-between gap-3">
        <ReportsScopeSelector scope={scope} onChange={setScope} />
        {/* Managing KPI definitions and targets lives in Settings → Administrator → Performance
            Management; this page stays a read-only analytics surface. */}
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => void exportPerformancePdf(scope.mode === "bd" ? scope.userId : undefined)}
            disabled={isExporting}
          >
            <Download className="h-4 w-4" />
            {isExporting ? "Exporting…" : "Export PDF"}
          </Button>
        </div>
      </div>

      <PerformanceScopeProvider scope={scope}>
        {isAllReports ? (
          // ---- Management dashboard (All Reports) — its own presentation, per the Management mockup ----
          <div className="flex flex-col gap-6">
            <ManagementKpiRow />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
              <ConversionFunnelCard className="lg:col-span-8" />
              <LeadStatusMixCard className="lg:col-span-4" />
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
              <OutreachByChannelCard className="lg:col-span-8" />
              <LeadAcquisitionCard className="lg:col-span-4" />
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
              <QuarterTargetsCard className="lg:col-span-8" />
              <DeclineReasonCard className="lg:col-span-4" />
            </div>

            <PendingFollowUpsCard />

            <LeadsByNicheCard />
          </div>
        ) : (
          // ---- BD scorecard (a specific BD) — the shared performance widgets, per the BD mockup ----
          <div className="flex flex-col gap-6">
            <PerformanceKpiRow />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
              <ConversionCard className="lg:col-span-3" />
              <OutreachTargetCard className="lg:col-span-5" />
              <FollowUpDisciplineCard className="lg:col-span-4" />
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
              <div className="lg:col-span-7">
                <MyRecentActivityCard />
              </div>
              <LeadAcquisitionCard className="lg:col-span-5" />
            </div>
          </div>
        )}
      </PerformanceScopeProvider>
    </div>
  );
}
