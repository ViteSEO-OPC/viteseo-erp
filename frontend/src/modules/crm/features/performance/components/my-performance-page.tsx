import { MyPerformanceToolbar } from "@/modules/crm/features/performance/components/my-performance-toolbar";
import { PerformanceKpiRow } from "@/modules/crm/features/performance/components/performance-kpi-row";
import { ConversionCard } from "@/modules/crm/features/performance/components/conversion-card";
import { OutreachTargetCard } from "@/modules/crm/features/performance/components/outreach-target-card";
import { FollowUpDisciplineCard } from "@/modules/crm/features/performance/components/follow-up-discipline-card";
import { MyRecentActivityCard } from "@/modules/crm/features/performance/components/my-recent-activity-card";

/**
 * "My Performance" — the BD-facing personal surface (S1) from BD-PERF-FE-ARCH-REVIEW.
 *
 * LIVE (real, ownership-scoped, server-computed): the six KPIs' five real cards (Conversion Rate,
 * Converted, Revenue, Active, Assigned), the Conversion donut, Outreach Progress (engagement
 * coverage), and My Recent Activity.
 * PENDING (honest — no fabricated values): Completed Follow-ups + Follow-up discipline await the
 * Planner module; Personal Target awaits a monthly-target configuration.
 * All computation stays server-side; no business formula lives in the frontend.
 */
export function MyPerformancePage() {
  return (
    <div className="flex flex-col gap-6">
      <MyPerformanceToolbar />

      <PerformanceKpiRow />

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <ConversionCard className="lg:col-span-3" />
        <OutreachTargetCard className="lg:col-span-5" />
        <FollowUpDisciplineCard className="lg:col-span-4" />
      </div>

      <MyRecentActivityCard />
    </div>
  );
}
