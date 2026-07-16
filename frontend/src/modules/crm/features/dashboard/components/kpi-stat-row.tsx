import { KpiStatCard } from "@/modules/crm/features/dashboard/components/kpi-stat-card";
import { KpiSkeleton } from "@/modules/crm/features/dashboard/components/kpi-skeleton";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { useDashboardKpis } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";

export function KpiStatRow() {
  const { data: kpis, isLoading, isError } = useDashboardKpis();

  if (isLoading) return <KpiSkeleton />;
  if (isError) return <ErrorState message="Failed to load KPIs" />;
  if (!kpis || kpis.length === 0) return null;

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6">
      {kpis.map((kpi) => (
        // Approved: primary KPI value at 33px (value font-size only; everything else unchanged).
        <KpiStatCard key={kpi.id} stat={kpi} valueSizeClassName="text-[33px]" />
      ))}
    </div>
  );
}
