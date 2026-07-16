"use client";

import { AlertTriangle } from "lucide-react";
import { StatCardChart } from "@/modules/crm/features/reports/components/stat-card-chart";
import { ConversionFunnel } from "@/modules/crm/features/reports/components/conversion-funnel";
import { NicheBars } from "@/modules/crm/features/reports/components/niche-bars";
import { WidgetPlaceholder } from "@/modules/crm/features/reports/components/widget-placeholder";
import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { LineChart } from "@/modules/crm/components/charts/line-chart/line-chart";
import { PieChart } from "@/modules/crm/components/charts/pie-chart/pie-chart";
import { useReportsData, type ReportsView } from "@/modules/crm/features/reports/hooks/use-reports-data";
import { useLeadsNiche } from "@/modules/crm/features/reports/hooks/use-leads-niche";

/**
 * Reports & Performance — Management analytics dashboard (Super Admin / Admin only; BDs use My
 * Performance). Route access is enforced by the RoleGuard in the dashboard layout; every LIVE figure
 * is a team-scoped backend aggregate (see useReportsData / useLeadsNiche) reusing existing endpoints.
 * Deferred widgets (needing a new backend concept) render honest placeholders, never fabricated data.
 * Responsive: two-column rows collapse to a single column below `lg`.
 */
export function ReportsGrid() {
  const { view, kpisLoading, statusLoading, statusError, acqLoading, acqError } = useReportsData();
  const niche = useLeadsNiche();

  // Text alternatives for the opaque (SVG) charts — screen readers get the real figures.
  const acqAria = view.acquisition
    ? `Monthly new leads, last 6 months: ${(view.acquisition[0]?.data ?? [])
        .map((d) => `${d.month} ${d.value}`)
        .join(", ")}.`
    : undefined;
  const statusAria = view.statusMix
    ? `Lead status mix, ${view.statusMix.total} total: ${view.statusMix.segments
        .map((s) => `${s.label} ${s.value}`)
        .join(", ")}.`
    : undefined;

  return (
    <div className="flex flex-col gap-6">
      {/* Team-wide management report only. Per-BD drill-down is a future backend capability and is
          intentionally NOT surfaced as a control until it is actually implemented. */}

      {/* KPI row — live team aggregates (performance summary). No fabricated trends. */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        {kpisLoading
          ? Array.from({ length: view.kpis.length }).map((_, i) => (
              <div
                key={i}
                className="h-[104px] animate-pulse rounded-xl border border-border bg-muted"
                aria-hidden
              />
            ))
          : view.kpis.map((kpi) => <StatCardChart key={kpi.id} card={kpi} />)}
      </div>

      {/* Conversion funnel (+ biggest drop-off) | Lead status mix */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
        <ChartCard
          title="Conversion funnel"
          subtitle="Where the pipeline narrows — All Leads → Engaged → Interested → Converted"
          className="lg:col-span-7"
          isLoading={kpisLoading || statusLoading}
          isError={statusError}
          isEmpty={!!view.funnel && view.funnel.total === 0}
          emptyMessage="No leads yet"
        >
          {view.funnel ? (
            <div className="flex flex-col gap-4 lg:flex-row lg:items-stretch">
              <div className="min-w-0 flex-1">
                <ConversionFunnel stages={view.funnel.stages} total={view.funnel.total} />
              </div>
              {view.dropOff ? <DropOffCallout dropOff={view.dropOff} /> : null}
            </div>
          ) : null}
        </ChartCard>

        <ChartCard
          title="Lead status mix"
          subtitle="Distribution across the pipeline"
          className="lg:col-span-5"
          isLoading={statusLoading}
          isError={statusError}
          isEmpty={!!view.statusMix && view.statusMix.total === 0}
          emptyMessage="No leads yet"
        >
          {view.statusMix ? (
            <div role="img" aria-label={statusAria}>
              <PieChart
                segments={view.statusMix.segments}
                centerLabel={`${view.statusMix.total} leads`}
              />
            </div>
          ) : null}
        </ChartCard>
      </div>

      {/* Outreach progress by channel (deferred) | Monthly lead acquisition */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
        <WidgetPlaceholder
          className="lg:col-span-7"
          title="Outreach progress by channel"
          subtitle="Email vs. cold calling — done, doing, remaining"
          reason="Needs a backend aggregate over the existing email_outreach / calling_status columns. Planned for the post-production-sync milestone."
        />
        <ChartCard
          title="Monthly lead acquisition"
          subtitle="Newly added leads, last 6 months"
          className="lg:col-span-5"
          isLoading={acqLoading}
          isError={acqError}
          isEmpty={!!view.acquisition && view.acquisition[0]?.data.every((d) => d.value === 0)}
          emptyMessage="No leads added yet"
        >
          {view.acquisition ? (
            <div role="img" aria-label={acqAria}>
              <LineChart series={view.acquisition} />
            </div>
          ) : null}
        </ChartCard>
      </div>

      {/* Quarter targets (deferred) | Decline reason analysis (deferred) */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <WidgetPlaceholder
          title="Quarter targets & follow-through"
          subtitle="Goal bars for management review"
          reason="Requires a Targets / Quotas model, which does not exist yet. Deferred pending product definition."
        />
        <WidgetPlaceholder
          title="Decline reason analysis"
          subtitle="Why did we lose them?"
          reason="Requires capturing a decline reason in the Lose flow plus an aggregate endpoint. Deferred — the Lose flow is frozen."
        />
      </div>

      {/* Leads by niche — reuses the /leads industry_counts SQL aggregate (top industries by count). */}
      <ChartCard
        title="Leads by niche"
        subtitle="Which markets are we fishing in? Top industries by lead count"
        isLoading={niche.isLoading}
        isError={niche.isError}
        isEmpty={!niche.isLoading && niche.top.length === 0}
        emptyMessage="No industry data yet"
      >
        <NicheBars items={niche.top} max={niche.max} />
      </ChartCard>
    </div>
  );
}

/** Biggest stage-to-stage loss in the funnel — pure presentation over existing funnel figures. */
function DropOffCallout({ dropOff }: { dropOff: NonNullable<ReportsView["dropOff"]> }) {
  return (
    <div className="flex shrink-0 flex-col justify-center gap-2 rounded-xl border border-border bg-muted/40 p-4 lg:w-52">
      <div className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
        <AlertTriangle className="h-4 w-4" aria-hidden /> Biggest drop-off
      </div>
      <p className="text-2xl font-extrabold text-red-500">{dropOff.dropPct}%</p>
      <p className="text-xs text-muted-foreground">
        {dropOff.from} → {dropOff.to}. This stage loses the most leads — the highest-leverage place to
        focus follow-up effort.
      </p>
    </div>
  );
}
