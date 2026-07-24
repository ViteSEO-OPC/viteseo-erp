"use client";

import { AlertTriangle } from "lucide-react";
import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { useReportsAnalytics } from "@/modules/crm/features/reports/hooks/use-reports-analytics";

/**
 * Conversion funnel — LIVE. Stage-to-stage survival Added → Confirmed, every count a server aggregate
 * over the scoped leads (definitions in the controller docblock). The bar width is % of the Added total;
 * the side insight surfaces the largest consecutive drop-off (where coaching pays off most).
 */
// Brand teal tokens (the exact mockup palette): the qualification stages carry the deep brand teal and
// the Confirmed/won stage is the bright brand teal — never green.
const STAGE_COLOR: Record<string, string> = {
  added: "var(--chart-teal-dark)",
  contacted: "var(--chart-teal-dark)",
  interested: "var(--chart-teal-dark)",
  consultation: "var(--chart-teal-dark)",
  confirmed: "var(--chart-teal)",
};

export function ConversionFunnelCard({ className }: { className?: string }) {
  const { data, isLoading, isError } = useReportsAnalytics();
  const stages = data?.funnel.stages ?? [];
  const drop = data?.funnel.biggestDropOff ?? null;
  const added = stages[0]?.value ?? 0;
  const max = Math.max(1, ...stages.map((s) => s.value));

  return (
    <ChartCard
      title="Conversion funnel"
      subtitle="Where do we lose leads? Stage-to-stage survival, Added → Confirmed"
      className={className}
      isLoading={isLoading}
      isError={isError}
      isEmpty={!isLoading && stages.length === 0}
    >
      <div className="flex flex-col gap-6 lg:flex-row">
        <div className="flex flex-1 flex-col gap-4">
          {stages.map((s) => {
            const pct = added > 0 ? Math.round((s.value / added) * 100) : 0;
            return (
              <div key={s.key} className="flex items-center gap-3">
                <span className="w-24 shrink-0 text-sm text-muted-foreground">{s.label}</span>
                <div className="h-3 flex-1 overflow-hidden rounded-full bg-secondary">
                  <div
                    className="h-full rounded-full transition-all"
                    style={{
                      width: `${Math.max(2, (s.value / max) * 100)}%`,
                      backgroundColor: STAGE_COLOR[s.key] ?? "var(--chart-teal)",
                    }}
                  />
                </div>
                <span className="w-10 text-right text-sm font-bold tabular-nums text-foreground">
                  {s.value}
                </span>
                <span className="w-10 text-right text-xs tabular-nums text-muted-foreground">
                  {pct}%
                </span>
              </div>
            );
          })}
        </div>

        {drop && (
          <div className="flex flex-col gap-2 rounded-xl border border-border bg-muted/40 p-4 lg:w-56">
            <div className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
              <AlertTriangle className="h-3.5 w-3.5 text-amber-500" aria-hidden />
              Biggest drop-off
            </div>
            <span className="text-3xl font-extrabold text-red-500">{drop.dropPct}%</span>
            <p className="text-xs leading-relaxed text-muted-foreground">
              {drop.from} → {drop.to}. This stage is where coaching and follow-up effort pay off most.
            </p>
          </div>
        )}
      </div>
    </ChartCard>
  );
}
