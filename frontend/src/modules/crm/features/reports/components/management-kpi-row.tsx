"use client";

import {
  ManagementKpiCard,
  type ManagementKpiCardData,
} from "@/modules/crm/features/reports/components/management-kpi-card";
import {
  useMyPerformanceSummary,
  formatPeso,
  formatConversionRate,
} from "@/modules/crm/features/performance/hooks/use-my-performance";
import { useReportsAnalytics } from "@/modules/crm/features/reports/hooks/use-reports-analytics";

/**
 * Management KPI row (All Reports only) — the five headline figures from the approved Management mockup:
 * Total Leads, Contacted, Conversion Rate, Consultations, Overall Revenue. Rendered with the dedicated
 * {@link ManagementKpiCard} (its own identity — NOT the BD scorecard's card). Every value is
 * server-computed and scope-aware (the funnel counts + the performance summary, both keyed by scope).
 */
export function ManagementKpiRow() {
  const { data: analytics, isLoading: aLoading } = useReportsAnalytics();
  const { data: summary, isLoading: sLoading } = useMyPerformanceSummary();

  const stages = analytics?.funnel.stages ?? [];
  const added = stages.find((s) => s.key === "added")?.value;
  const contacted = stages.find((s) => s.key === "contacted")?.value;
  // Consultations HELD — the frozen business rule (unique leads with a completed consultation the lead
  // attended). Deliberately NOT the funnel's `consultation` stage, which counts scheduled slots and is
  // untouched pending the Funnel Review, so the two legitimately differ.
  const consultation = analytics?.consultationsHeld;
  const contactedPct =
    added && added > 0 && contacted !== undefined ? Math.round((contacted / added) * 100) : undefined;

  const cards: ManagementKpiCardData[] = [
    {
      id: "total-leads",
      label: "Total Leads",
      icon: "leads",
      value: added !== undefined ? String(added) : "—",
      caption: "In pipeline",
    },
    {
      id: "contacted",
      label: "Contacted",
      icon: "contacted",
      value: contactedPct !== undefined ? `${contactedPct}%` : "—",
      caption:
        contacted !== undefined && added !== undefined ? `${contacted} of ${added}` : "Of total leads",
    },
    {
      id: "conversion-rate",
      label: "Conversion Rate",
      icon: "conversion",
      value: formatConversionRate(summary?.conversionRate),
      caption: "Of engaged leads",
    },
    {
      id: "consultations",
      label: "Consultations",
      icon: "consultations",
      value: consultation !== undefined ? String(consultation) : "—",
      caption: "Leads consulted",
    },
    {
      id: "overall-revenue",
      label: "Overall Revenue",
      icon: "revenue",
      value: formatPeso(summary?.overallRevenue),
      caption: "From converted leads",
    },
  ];

  const isLoading = aLoading || sLoading;

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
      {cards.map((card) => (
        <ManagementKpiCard key={card.id} card={card} isLoading={isLoading} />
      ))}
    </div>
  );
}
