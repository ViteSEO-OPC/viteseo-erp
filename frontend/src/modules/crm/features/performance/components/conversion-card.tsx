"use client";

import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { PieChart } from "@/modules/crm/components/charts/pie-chart/pie-chart";
import {
  useMyPerformanceSummary,
  formatConversionRate,
} from "@/modules/crm/features/performance/hooks/use-my-performance";
import {
  scopeCopy,
  usePerformanceScope,
} from "@/modules/crm/features/performance/lib/performance-scope";

/**
 * Conversion donut — LIVE. Shows the ownership-scoped conversion rate (converted ÷ engaged) from
 * the performance summary; the ring splits converted vs remaining. Reuses the shared ChartCard
 * (loading/error) + PieChart. No client-side computation — the rate comes from the backend.
 */
export function ConversionCard({ className }: { className?: string }) {
  const { data: s, isLoading, isError } = useMyPerformanceSummary();
  const copy = scopeCopy(usePerformanceScope());

  const rate = s?.conversionRate ?? null; // null → undefined (no engaged leads)
  const converted = s?.convertedLeads ?? 0;
  const engaged = s?.engagedLeads ?? 0;

  const segments = [
    { label: "Converted", value: rate ?? 0, color: "var(--chart-1, #4FD8D7)" },
    { label: "Remaining", value: 100 - (rate ?? 0), color: "var(--chart-gray, #E5E7EB)" },
  ];

  return (
    <ChartCard
      title="Conversion"
      subtitle={copy.conversionSubtitle}
      className={className}
      isLoading={isLoading}
      isError={isError}
    >
      <div className="flex flex-col items-center gap-4">
        <PieChart segments={segments} centerLabel={formatConversionRate(rate)} showLegend={false} />
        <p className="text-center text-xs text-muted-foreground">
          {converted} of {engaged} engaged leads converted
        </p>
      </div>
    </ChartCard>
  );
}
