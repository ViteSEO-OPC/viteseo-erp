"use client";

import type { FunnelStage } from "@/modules/crm/features/reports/hooks/use-reports-data";

type Props = {
  stages: FunnelStage[];
  total: number;
};

/**
 * Conversion funnel — presentation only. Every stage value is a live backend metric (see
 * useReportsData); this component just draws each stage's bar scaled to the pipeline total and shows
 * its share. No business computation, no lifecycle stages beyond the frozen model.
 */
export function ConversionFunnel({ stages, total }: Props) {
  return (
    <div className="flex flex-col gap-3">
      {stages.map((stage) => {
        const pct = total > 0 ? Math.round((stage.value / total) * 100) : 0;
        return (
          <div
            key={stage.label}
            className="flex items-center gap-3"
            aria-label={`${stage.label}: ${stage.value} leads, ${pct}% of pipeline`}
          >
            <span className="w-20 shrink-0 truncate text-xs text-secondary-foreground" aria-hidden>
              {stage.label}
            </span>
            <div className="h-3 flex-1 rounded-full bg-secondary" aria-hidden>
              <div
                className="h-3 rounded-full transition-all"
                style={{ width: `${pct}%`, backgroundColor: stage.color }}
              />
            </div>
            <span
              className="w-12 shrink-0 text-right text-sm font-semibold text-foreground"
              aria-hidden
            >
              {stage.value}
            </span>
            <span className="w-9 shrink-0 text-right text-xs text-muted-foreground" aria-hidden>
              {pct}%
            </span>
          </div>
        );
      })}
    </div>
  );
}
