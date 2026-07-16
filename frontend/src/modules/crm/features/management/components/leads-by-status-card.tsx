"use client";

import Link from "next/link";
import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { LabeledBarList } from "@/modules/crm/components/shared/labeled-bar-list/labeled-bar-list";
import { useLeadStatusBreakdown } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";

/**
 * Admin/SA "Leads by Status" — live counts from the real Leads table via the existing dashboard
 * KPIs query (`useLeadStatusBreakdown` → backend GROUP BY status). The shared `LabeledBarList`
 * scales every bar against the largest status count (largest = full width, others proportional)
 * and renders 0-value statuses as dashed 0-bars, so no `max` is passed and no numbers are
 * hardcoded. Loading / empty / error use ChartCard's shared states.
 */
export function LeadsByStatusCard({ className }: { className?: string }) {
  const { data: rows, isLoading, isError } = useLeadStatusBreakdown();

  return (
    <ChartCard
      title="Leads by Status"
      filter={
        <Link href="/zerem/leads" className="text-xs font-semibold text-teal-700 hover:underline">
          View leads →
        </Link>
      }
      className={className}
      isLoading={isLoading}
      isError={isError}
      isEmpty={!!rows && rows.every((row) => row.value === 0)}
      emptyMessage="No leads yet"
    >
      {rows ? <LabeledBarList rows={rows} /> : null}
    </ChartCard>
  );
}
