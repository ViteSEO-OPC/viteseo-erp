"use client";

import { PerformanceKpiCard } from "@/modules/crm/features/performance/components/performance-kpi-card";
import {
  useMyPerformanceSummary,
  formatPeso,
  formatConversionRate,
} from "@/modules/crm/features/performance/hooks/use-my-performance";
import {
  scopeCopy,
  usePerformanceScope,
} from "@/modules/crm/features/performance/lib/performance-scope";
import type { PerfKpiCard } from "@/modules/crm/features/performance/types/performance-types";

/**
 * The KPI row. Live from the ownership-scoped performance summary: Conversion Rate, Converted Leads,
 * Overall Revenue (Σ deals.amount), **Estimated Pipeline Value** (Σ leads.amount — prospect estimate,
 * distinct from revenue), Active Leads, Assigned Leads, and "Completed Follow-ups" (follow-up Planner
 * events completed this calendar month, from the real `completed_at` stamp). No trend chips — real
 * values carry no invented month-over-month delta. All values are server-computed. Copy is
 * scope-aware, so the same row serves My Performance and the Reports & Performance BD scorecard.
 */
export function PerformanceKpiRow() {
  const { data: s, isLoading } = useMyPerformanceSummary();
  const copy = scopeCopy(usePerformanceScope());

  const liveCards: PerfKpiCard[] = [
    {
      id: "conversion-rate",
      label: "Conversion Rate",
      icon: "conversion",
      value: formatConversionRate(s?.conversionRate),
      valueTone: "accent",
      caption: "Of engaged leads",
    },
    {
      id: "converted-leads",
      label: "Converted Leads",
      icon: "converted",
      value: s ? String(s.convertedLeads) : "—",
      valueTone: "neutral",
      caption: "Won deals",
    },
    {
      id: "overall-revenue",
      label: "Overall Revenue",
      icon: "revenue",
      value: formatPeso(s?.overallRevenue),
      valueTone: "accent",
      caption: "From converted leads",
    },
    {
      id: "estimated-pipeline-value",
      label: "Estimated Pipeline",
      icon: "pipeline",
      value: formatPeso(s?.estimatedPipelineValue),
      valueTone: "accent",
      caption: "Est. value of open leads",
    },
    {
      id: "active-leads",
      label: "Active Leads",
      icon: "active",
      value: s ? String(s.activeLeads) : "—",
      valueTone: "neutral",
      caption: "Still being worked",
    },
    {
      id: "assigned-leads",
      label: "Assigned Leads",
      icon: "assigned",
      value: s ? String(s.assignedLeads) : "—",
      valueTone: "neutral",
      caption: copy.assignedCaption,
    },
  ];

  // LIVE — follow-up Planner events completed this calendar month (real `completed_at`, ownership-scoped).
  const followUpsCard: PerfKpiCard = {
    id: "completed-follow-ups",
    label: "Completed Follow-ups",
    icon: "followups",
    value: s ? String(s.completedFollowUps) : "—",
    valueTone: "neutral",
    caption: "This month",
  };

  const cards = [...liveCards, followUpsCard];

  // Seven KPIs. Single row at 2xl (7-across); laptops fall back to a readable 3/4-across so the KPI
  // labels never truncate under the icon + badge.
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-7">
      {cards.map((card) => (
        <PerformanceKpiCard
          key={card.id}
          card={card}
          isLoading={!card.pending && isLoading}
        />
      ))}
    </div>
  );
}
