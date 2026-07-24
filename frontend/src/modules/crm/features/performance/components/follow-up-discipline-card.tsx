"use client";

import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { cn } from "@/modules/crm/lib/utils";
import { useMyPerformanceSummary } from "@/modules/crm/features/performance/hooks/use-my-performance";

/**
 * Follow-up discipline — LIVE. Missed follow-ups / appointments / calls from the Planner module, using
 * the PO-frozen rule: an event is "missed" when its `starts_at` is in the past and it is not completed
 * (grouped by follow-up / meeting / call). Ownership-scoped via the performance summary. No fabricated
 * period-over-period deltas — those wait on a real business computation.
 */
const ROWS = [
  { key: "missedFollowUps", label: "Missed follow-ups", color: "bg-amber-400" },
  { key: "missedMeetings", label: "Missed appointments", color: "bg-red-400" },
  { key: "missedCalls", label: "Missed calls", color: "bg-blue-400" },
] as const;

export function FollowUpDisciplineCard({ className }: { className?: string }) {
  const { data: s, isLoading, isError } = useMyPerformanceSummary();
  const discipline = s?.followUpDiscipline;
  const max = discipline
    ? Math.max(1, discipline.missedFollowUps, discipline.missedMeetings, discipline.missedCalls)
    : 1;

  return (
    <ChartCard
      title="Follow-up discipline"
      subtitle="Missed follow-ups, appointments, and calls"
      className={className}
      isLoading={isLoading}
      isError={isError}
    >
      <div className="flex flex-col gap-5">
        {ROWS.map((row) => {
          const value = discipline?.[row.key] ?? 0;
          const width = value === 0 ? 0 : Math.max(6, Math.round((value / max) * 100));
          return (
            <div key={row.key}>
              <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                  <span className={cn("h-2.5 w-2.5 rounded-full", row.color)} aria-hidden />
                  <span className="text-sm font-semibold text-card-foreground">{row.label}</span>
                </div>
                <span className="text-lg font-bold tabular-nums text-card-foreground">{value}</span>
              </div>
              <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-secondary">
                <div className={cn("h-full rounded-full", row.color)} style={{ width: `${width}%` }} />
              </div>
            </div>
          );
        })}
      </div>
    </ChartCard>
  );
}
