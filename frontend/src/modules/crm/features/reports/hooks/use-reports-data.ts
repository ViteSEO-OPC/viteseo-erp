"use client";

import {
  useMyPerformanceSummary,
  formatConversionRate,
  formatPeso,
} from "@/modules/crm/features/performance/hooks/use-my-performance";
import { useLeadStatusBreakdown } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";
import { useLeadAcquisition } from "@/modules/crm/features/management/hooks/use-lead-acquisition";
import type { KpiCard, DonutSegment, LineSeries } from "@/modules/crm/features/reports/types/reports-types";

/**
 * Reports & Performance — Management analytics. Phase 1 (REUSE): every figure is a LIVE, backend-owned
 * aggregate, consumed from the EXISTING ownership-scoped queries (shared React-Query keys → no
 * duplicate request or business computation). Because the module is Management-only, the acting user
 * is Admin/Super Admin, whose `Lead::scopeVisibleTo` resolves to the whole tenant — so these are
 * team-wide totals. No mock data, no fabricated trends. (Funnel / niche / BD filter = Phase 2;
 * outreach-by-channel = Phase 3.)
 */
export type FunnelStage = { label: string; value: number; color: string };
export type DropOff = { from: string; to: string; dropPct: number } | undefined;

export type ReportsView = {
  kpis: KpiCard[];
  statusMix: { segments: DonutSegment[]; total: number } | undefined;
  acquisition: LineSeries[] | undefined;
  funnel: { stages: FunnelStage[]; total: number } | undefined;
  dropOff: DropOff;
};

export function useReportsData() {
  // One aggregate query drives the KPI row (active/engaged/converted/revenue/conversion), scoped
  // server-side. For Admin/SA that is the full tenant.
  const { data: summary, isLoading: summaryLoading, isError: summaryError } =
    useMyPerformanceSummary();
  // Live GROUP BY status (the frozen 5-status model — labels/colors from the central taxonomy).
  const { data: statusRows, isLoading: statusLoading, isError: statusError } =
    useLeadStatusBreakdown();
  // Server-aggregated monthly new-lead counts (created_at buckets); descriptive only.
  const { points, isLoading: acqLoading, isError: acqError } = useLeadAcquisition();

  const kpis: KpiCard[] = [
    {
      // Canonical denominator for the whole page = ALL qualified leads (`assignedLeads` =
      // Lead::active(), across every status incl. declined). This MATCHES the Lead-status-mix donut
      // total (same active()+visibleTo scope), so the page presents one consistent "total leads".
      // (`activeLeads` — the ACTIVE_SET that excludes declined — is a different, narrower concept and
      // is intentionally not used as the page denominator.)
      id: "total-leads",
      label: "Total Leads",
      value: summary ? String(summary.assignedLeads) : "—",
      subCaption: "All leads across statuses",
    },
    {
      id: "engaged-leads",
      label: "Engaged Leads",
      value: summary ? String(summary.engagedLeads) : "—",
      subCaption: "Progressed past New",
    },
    {
      id: "converted-leads",
      label: "Converted Leads",
      value: summary ? String(summary.convertedLeads) : "—",
      subCaption: "Won business",
    },
    {
      id: "conversion-rate",
      label: "Conversion Rate",
      value: formatConversionRate(summary?.conversionRate),
      subCaption: "Converted ÷ engaged",
    },
    {
      id: "overall-revenue",
      label: "Overall Revenue",
      value: summary ? formatPeso(summary.overallRevenue) : "—",
      subCaption: "From converted leads",
    },
  ];

  const statusMix = statusRows
    ? {
        segments: statusRows.map((r) => ({ label: r.label, value: r.value, color: r.color })),
        total: statusRows.reduce((sum, r) => sum + r.value, 0),
      }
    : undefined;

  const acquisition: LineSeries[] | undefined = points
    ? [
        {
          name: "New leads",
          data: points.map((p) => ({ month: p.month, value: p.value })),
          color: "var(--chart-teal, #4FD8D7)",
        },
      ]
    : undefined;

  // Conversion funnel — Phase 2 EXTEND, but frontend-only: composed entirely from EXISTING approved
  // metrics (no new endpoint, no new lifecycle stage). Stages narrow through the real pipeline:
  // All Leads (assignedLeads) → Engaged (M-04 engagement basis) → Interested (frozen status) →
  // Converted (has ≥1 Deal). Each value is a backend figure; only the bar %/width is presentation.
  const interested = statusRows?.find((r) => r.label === "Interested")?.value;
  const funnel =
    summary && interested !== undefined
      ? {
          total: summary.assignedLeads,
          stages: [
            { label: "All Leads", value: summary.assignedLeads, color: "var(--chart-teal-dark, #10B7B3)" },
            { label: "Engaged", value: summary.engagedLeads, color: "var(--chart-teal, #4FD8D7)" },
            { label: "Interested", value: interested, color: "var(--chart-teal-light, #76E9E6)" },
            { label: "Converted", value: summary.convertedLeads, color: "var(--color-success, #15803D)" },
          ] as FunnelStage[],
        }
      : undefined;

  // Biggest stage-to-stage drop-off — pure presentation over the EXISTING funnel figures (no
  // backend, no new metric): the consecutive transition with the largest percentage loss.
  const dropOff: DropOff = (() => {
    if (!funnel || funnel.stages.length < 2) return undefined;
    let worst: { from: string; to: string; dropPct: number } | undefined;
    for (let i = 1; i < funnel.stages.length; i += 1) {
      const prev = funnel.stages[i - 1];
      const cur = funnel.stages[i];
      if (!prev || !cur) continue;
      const dropPct =
        prev.value > 0 ? Math.round(((cur.value - prev.value) / prev.value) * 100) : 0;
      if (!worst || dropPct < worst.dropPct) {
        worst = { from: prev.label, to: cur.label, dropPct };
      }
    }
    return worst;
  })();

  const view: ReportsView = { kpis, statusMix, acquisition, funnel, dropOff };

  return {
    view,
    kpisLoading: summaryLoading,
    statusLoading,
    statusError,
    acqLoading,
    acqError,
    isLoading: summaryLoading || statusLoading || acqLoading,
    isError: summaryError || statusError || acqError,
  };
}
