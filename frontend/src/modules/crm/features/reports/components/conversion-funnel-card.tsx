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

  // Biggest drop-off, derived entirely from data already in THIS response — the stage counts and the
  // drop-off's own from/to labels. No new endpoint, no backend change, no recomputed business rule.
  //
  // `dropPct` is the API's signed percent change. It is negative for a real loss, but the backend picks
  // the MINIMUM change across stages, which can be zero or positive when no stage shrank (reachable
  // today because `Confirmed` is computed independently of the cohort). So the sign — not the magnitude
  // — decides whether this is a drop at all; only then are a loss figure and a "leads lost" count
  // truthful.
  const isRealDrop = drop !== null && drop.dropPct < 0;
  const lossPct = drop !== null ? Math.max(0, -drop.dropPct) : 0;
  // `from`/`to` are stage LABELS, which is exactly what the stage rows carry — so the counts come from
  // the same aggregates the bars render, never from a second source that could disagree.
  const fromStage = drop !== null ? stages.find((s) => s.label === drop.from) : undefined;
  const toStage = drop !== null ? stages.find((s) => s.label === drop.to) : undefined;
  const leadsLost =
    fromStage !== undefined && toStage !== undefined ? fromStage.value - toStage.value : null;

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
            {isRealDrop ? (
              <>
                {/* Unsigned magnitude + the word that gives it meaning, so the figure never reads as
                    "minus 76% drop-off". Hierarchy: label → percentage → transition → counts. */}
                <p className="flex items-baseline gap-1.5">
                  <span className="text-3xl font-extrabold tabular-nums text-red-500">{lossPct}%</span>
                  <span className="text-sm font-semibold text-red-500">drop</span>
                </p>
                <p className="text-xs font-medium leading-relaxed text-foreground">
                  {drop.from} → {drop.to}
                </p>
                {/* Counts only when both stages resolved — the same aggregates the bars above render. */}
                {leadsLost !== null && (
                  <p className="text-xs leading-relaxed text-muted-foreground">
                    <span className="tabular-nums">{fromStage?.value}</span> →{" "}
                    <span className="tabular-nums">{toStage?.value}</span> leads
                    <br />
                    <span className="tabular-nums font-semibold text-foreground">{leadsLost}</span>{" "}
                    {leadsLost === 1 ? "lead" : "leads"} lost
                  </p>
                )}
              </>
            ) : (
              /* No stage shrank. Saying "0% drop" beside "0 leads lost" would dress a non-event as a
                 finding, so state the fact plainly and show no loss figures at all. */
              <p className="text-sm font-semibold text-foreground">No stage drop</p>
            )}
          </div>
        )}
      </div>
    </ChartCard>
  );
}
