"use client";

import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { PendingBadge } from "@/modules/crm/components/shared/pending-badge/pending-badge";
import { cn } from "@/modules/crm/lib/utils";
import { useReportsAnalytics } from "@/modules/crm/features/reports/hooks/use-reports-analytics";

/**
 * Quarter targets & follow-through. The RIGHT tiles are LIVE: missed follow-ups / appointments / calls
 * (the PO-frozen "missed" rule) and outreach completion %. The LEFT column shows the real progress
 * counts (leads contacted, consultations actually held, confirmed) but carries a **Pending** marker instead of a
 * fabricated goal bar — targets arrive with Goal Management (frozen Business Decision: no hardcoded
 * goals). Every number here is server-computed; nothing is invented to fill the mockup.
 */
export function QuarterTargetsCard({ className }: { className?: string }) {
  const { data, isLoading, isError } = useReportsAnalytics();
  const f = data?.followThrough;

  const progress = [
    { label: "Leads contacted", value: f?.leadsContacted },
    { label: "Consultations", value: f?.consultations },
    { label: "Confirmed clients", value: f?.confirmedClients },
  ];

  const tiles = [
    { label: "Missed follow-ups", value: f?.missedFollowUps, tone: "text-red-500" },
    { label: "Missed calls", value: f?.missedCalls, tone: "text-amber-500" },
    { label: "Missed appointments", value: f?.missedMeetings, tone: "text-red-500" },
    // Operational queue, NOT a target: consultations concluded with no outcome recorded yet. They are
    // excluded from the Consultations KPI until a BD records the real result, so this is the work
    // queue that keeps that exclusion visible instead of silent.
    { label: "Awaiting outcome", value: f?.consultationsAwaitingOutcome, tone: "text-amber-500" },
    {
      label: "Outreach completion",
      value: f?.outreachCompletionPct,
      tone: "text-emerald-600",
      suffix: "%",
    },
  ];

  return (
    <ChartCard
      title="Quarter targets & follow-through"
      subtitle="Live team counts for management review; goals arrive with Goal Management"
      className={className}
      isLoading={isLoading}
      isError={isError}
      filter={<PendingBadge reason="Quarter targets await the Goal Management module — counts are live, goals are not set" />}
    >
      <div className="flex flex-col gap-6 lg:flex-row">
        <div className="flex flex-1 flex-col justify-center gap-4">
          {progress.map((p) => (
            <div key={p.label} className="flex items-baseline justify-between gap-3">
              <span className="text-sm text-card-foreground">{p.label}</span>
              <span className="flex items-baseline gap-1.5">
                <span className="text-lg font-bold tabular-nums text-foreground">
                  {p.value ?? "—"}
                </span>
                <span className="text-[11px] uppercase tracking-wide text-muted-foreground">
                  / target pending
                </span>
              </span>
            </div>
          ))}
        </div>

        <div className="grid grid-cols-2 gap-3 lg:w-64">
          {tiles.map((t) => (
            <div
              key={t.label}
              className="flex flex-col items-center justify-center gap-1 rounded-xl border border-border bg-muted/30 p-3 text-center"
            >
              <span className={cn("text-2xl font-extrabold tabular-nums", t.tone)}>
                {t.value ?? "—"}
                {t.value !== undefined && t.value !== null ? t.suffix ?? "" : ""}
              </span>
              <span className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                {t.label}
              </span>
            </div>
          ))}
        </div>
      </div>
    </ChartCard>
  );
}
