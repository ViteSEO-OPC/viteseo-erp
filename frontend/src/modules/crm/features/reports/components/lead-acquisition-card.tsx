"use client";

import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { LineChart } from "@/modules/crm/components/charts/line-chart/line-chart";
import { useLeadAcquisition } from "@/modules/crm/features/management/hooks/use-lead-acquisition";

/**
 * Monthly lead-acquisition trend — the existing line-chart widget, reused verbatim. Per sprint decision
 * #7 it is kept EXACTLY as it is (title, "newly added leads" semantics, design) and is NOT scoped per BD
 * this sprint; a future sprint replaces it with a real "leads contacted per month" metric. Reuses the
 * shared ChartCard + LineChart + the existing GET /dashboard/lead-acquisition data — no new endpoint.
 */
export function LeadAcquisitionCard({ className }: { className?: string }) {
  const { points, isLoading, isError } = useLeadAcquisition();

  const series = points
    ? [
        {
          name: "New leads",
          data: points.map((p) => ({ month: p.month, value: p.value })),
          color: "var(--chart-teal, #4FD8D7)",
        },
      ]
    : undefined;

  const aria = points
    ? `Monthly new leads, last 6 months: ${points.map((p) => `${p.month} ${p.value}`).join(", ")}`
    : undefined;

  return (
    <ChartCard
      title="Monthly lead acquisition"
      subtitle="Newly added leads, last 6 months"
      className={className}
      isLoading={isLoading}
      isError={isError}
      isEmpty={!!points && points.every((p) => p.value === 0)}
      emptyMessage="No leads added yet"
    >
      {series ? (
        <div role="img" aria-label={aria}>
          <LineChart series={series} />
        </div>
      ) : null}
    </ChartCard>
  );
}
