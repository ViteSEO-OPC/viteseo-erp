"use client";

import { Card, CardContent } from "@/modules/crm/components/ui/card";
import { PendingBadge } from "@/modules/crm/components/shared/pending-badge/pending-badge";
import { cn } from "@/modules/crm/lib/utils";
import { useMyPerformanceSummary } from "@/modules/crm/features/performance/hooks/use-my-performance";
import { useKpiMetrics, findMetric } from "@/modules/crm/features/performance/hooks/use-kpi-metrics";

function Meter({ pct, barClass }: { pct: number; barClass: string }) {
  return (
    <div className="h-2.5 w-full overflow-hidden rounded-full bg-secondary">
      <div className={cn("h-full rounded-full", barClass)} style={{ width: `${pct}%` }} />
    </div>
  );
}

/**
 * Outreach Progress + Personal Target.
 *
 * **Outreach Progress — LIVE.** The percentage is **M-04 Engagement Coverage, computed by the BD KPI
 * Engine** (`GET /dashboard/kpi-metrics`) and consumed directly — the frontend performs **no** business
 * computation. The KPI Engine is the single authoritative owner of M-04; the formula is never
 * duplicated here. The descriptive footer ("X of Y contacted") uses the raw counts from the performance
 * summary as display data only.
 *
 * **Personal Target — Pending Configuration.** A monthly conversion target is not yet a configurable
 * value, so no progress can be computed. It shows a Pending state with no fabricated percentage.
 */
export function OutreachTargetCard({ className }: { className?: string }) {
  const { data: s, isLoading: summaryLoading } = useMyPerformanceSummary();
  const { data: metrics, isLoading: kpiLoading } = useKpiMetrics();

  const isLoading = summaryLoading || kpiLoading;

  // M-04 Engagement Coverage % — read straight from the KPI Engine (server-computed). `value` is null
  // when not evaluable (no assigned leads); the meter/label then read 0 and the footer explains why.
  const m04 = findMetric(metrics, "M-04");
  const coverage =
    m04?.evaluable && typeof m04.value === "number" ? m04.value : null;
  const pct = coverage !== null ? Math.round(coverage) : 0; // round for display only, not computation

  // Raw counts — descriptive footer data only (never used to derive the percentage).
  const assigned = s?.assignedLeads ?? 0;
  const engaged = s?.engagedLeads ?? 0;
  const remaining = Math.max(0, assigned - engaged);

  return (
    <Card className={cn("border-border shadow-card", className)}>
      <CardContent className="flex flex-col gap-6 pt-4">
        {/* Outreach Progress — live engagement coverage */}
        <div>
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <h3 className="text-sm font-bold text-card-foreground">Outreach Progress</h3>
              <p className="text-xs text-muted-foreground">
                Assigned leads you have contacted by email or call.
              </p>
            </div>
            {isLoading ? (
              <span className="h-6 w-12 shrink-0 animate-pulse rounded bg-muted" />
            ) : (
              <span className="shrink-0 text-lg font-bold text-primary-accent">{pct}%</span>
            )}
          </div>
          <div className="mt-3">
            <Meter pct={isLoading ? 0 : pct} barClass="bg-teal-400" />
          </div>
          <p className="mt-2 text-xs text-muted-foreground">
            {isLoading
              ? "Loading…"
              : assigned > 0
                ? `${engaged} of ${assigned} assigned leads contacted · ${remaining} remaining`
                : "No assigned leads yet"}
          </p>
        </div>

        {/* Personal Target — pending configuration (no monthly target module) */}
        <div>
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <h3 className="text-sm font-bold text-card-foreground">Personal Target</h3>
              <p className="text-xs text-muted-foreground">
                Converted leads against your monthly goal.
              </p>
            </div>
            <PendingBadge reason="Pending configuration — no monthly target has been set" />
          </div>
          <p className="mt-3 text-xs text-muted-foreground">
            Set a monthly conversion target to track progress here.
          </p>
        </div>
      </CardContent>
    </Card>
  );
}
