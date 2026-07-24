"use client";

import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { PieChart } from "@/modules/crm/components/charts/pie-chart/pie-chart";
import { LEAD_STATUS_CONFIG } from "@/modules/crm/lib/lead-status";
import { useReportsAnalytics } from "@/modules/crm/features/reports/hooks/use-reports-analytics";

/**
 * Lead status mix — LIVE. A donut of the scoped status distribution (GROUP BY status server-side),
 * with labels and colours read from the central {@link LEAD_STATUS_CONFIG} so the taxonomy stays
 * single-sourced. The legend carries each status's count and share of the total.
 */
export function LeadStatusMixCard({ className }: { className?: string }) {
  const { data, isLoading, isError } = useReportsAnalytics();
  const mix = data?.statusMix;

  const segments = LEAD_STATUS_CONFIG.map((s) => ({
    label: s.label,
    value: mix?.[s.value] ?? 0,
    color: s.color,
  }));
  const total = segments.reduce((sum, s) => sum + s.value, 0);

  return (
    <ChartCard
      title="Lead status mix"
      subtitle="Outcome mix across all active leads"
      className={className}
      isLoading={isLoading}
      isError={isError}
      isEmpty={!isLoading && total === 0}
    >
      <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-center">
        <div className="w-28 shrink-0">
          <PieChart segments={segments} centerLabel={String(total)} showLegend={false} />
          <p className="mt-1 text-center text-[11px] text-muted-foreground">active leads</p>
        </div>
        <ul className="flex min-w-0 flex-1 flex-col gap-2.5">
          {segments.map((s) => {
            const pct = total > 0 ? Math.round((s.value / total) * 100) : 0;
            return (
              <li key={s.label} className="flex items-center gap-2 text-sm">
                <span
                  className="h-2.5 w-2.5 shrink-0 rounded-full"
                  style={{ backgroundColor: s.color }}
                  aria-hidden
                />
                <span className="flex-1 leading-tight text-card-foreground">{s.label}</span>
                <span className="shrink-0 tabular-nums">
                  <span className="font-semibold text-foreground">{s.value}</span>
                  <span className="text-xs text-muted-foreground"> · {pct}%</span>
                </span>
              </li>
            );
          })}
        </ul>
      </div>
    </ChartCard>
  );
}
