"use client";

import { PerformanceKpiCard } from "@/modules/crm/features/performance/components/performance-kpi-card";
import {
  useMyPerformanceSummary,
  formatPeso,
  formatConversionRate,
} from "@/modules/crm/features/performance/hooks/use-my-performance";
import type { PerfKpiCard } from "@/modules/crm/features/performance/types/performance-types";

/**
 * The six-card KPI row. Five cards are LIVE from the ownership-scoped performance summary
 * (Conversion Rate, Converted Leads, Overall Revenue, Active Leads, Assigned Leads). "Completed
 * Follow-ups" is **Pending Planner integration** — there is no follow-up/appointment module yet, so it
 * shows a Pending badge and no value (never a fabricated number). No trend chips — real values carry
 * no invented month-over-month delta (the frozen spec forbids fabricated trends).
 */
export function PerformanceKpiRow() {
  const { data: s, isLoading } = useMyPerformanceSummary();

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
      caption: "Leads assigned to you",
    },
  ];

  // Pending — no follow-up/appointment module exists yet (an outbound email is not a follow-up).
  // Shown as Pending Planner integration, with no fabricated value.
  const followUpsCard: PerfKpiCard = {
    id: "completed-follow-ups",
    label: "Completed Follow-ups",
    icon: "followups",
    value: "—",
    valueTone: "neutral",
    caption: "Awaiting Planner module",
    pending: true,
    pendingReason: "Pending Planner integration — no follow-up/appointment module yet",
  };

  const cards = [...liveCards, followUpsCard];

  // 6-across single row only at 2xl (the approved design's presentation width); laptops fall back
  // to a readable 3-across so the KPI labels never truncate under the icon + Demo badge.
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
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
