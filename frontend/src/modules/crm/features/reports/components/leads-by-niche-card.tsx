"use client";

import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { useReportsAnalytics } from "@/modules/crm/features/reports/hooks/use-reports-analytics";

/**
 * Leads by niche — LIVE. A horizontal ranking of the scoped lead count per industry (GROUP BY industry
 * server-side; industry IS the niche — no taxonomy mapping), top markets first. Bar width is relative
 * to the leading niche.
 */
// Brand teal ramp (exact mockup palette): the ranking darkens-to-lightens down the list.
const BAR_SHADE = [
  "var(--chart-teal-dark)",
  "var(--chart-teal-dark)",
  "var(--chart-teal)",
  "var(--chart-teal)",
  "var(--chart-teal-light)",
  "var(--chart-teal-light)",
  "var(--chart-teal-lightest)",
  "var(--chart-teal-lightest)",
];

export function LeadsByNicheCard({ className }: { className?: string }) {
  const { data, isLoading, isError } = useReportsAnalytics();
  const niche = data?.niche ?? [];
  const max = Math.max(1, ...niche.map((n) => n.value));

  return (
    <ChartCard
      title="Leads by niche"
      subtitle="Which markets are we fishing in?"
      className={className}
      isLoading={isLoading}
      isError={isError}
      isEmpty={!isLoading && niche.length === 0}
    >
      <div className="flex flex-col gap-3">
        {niche.map((n, i) => (
          <div key={n.niche} className="flex items-center gap-3">
            <span className="w-32 shrink-0 truncate text-sm text-card-foreground" title={n.niche}>
              {n.niche}
            </span>
            <div className="h-5 flex-1 overflow-hidden rounded-full bg-secondary">
              <div
                className="h-full rounded-full transition-all"
                style={{
                  width: `${Math.max(3, (n.value / max) * 100)}%`,
                  backgroundColor: BAR_SHADE[Math.min(i, BAR_SHADE.length - 1)],
                }}
              />
            </div>
            <span className="w-8 text-right text-sm font-bold tabular-nums text-foreground">
              {n.value}
            </span>
          </div>
        ))}
      </div>
    </ChartCard>
  );
}
