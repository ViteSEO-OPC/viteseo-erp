"use client";

import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { BarChart } from "@/modules/crm/components/charts/bar-chart/bar-chart";
import { useLeadAcquisition } from "@/modules/crm/features/management/hooks/use-lead-acquisition";

export function LeadAcquisitionCard({ className }: { className?: string }) {
  const { points, summary, isLoading, isError } = useLeadAcquisition();
  const max =
    points && points.length ? Math.max(...points.map((p) => p.value)) + 8 : 0;

  return (
    <ChartCard
      title="Lead Acquisition"
      subtitle="New leads added · last 6 months"
      filter={
        <div className="flex items-center gap-3 text-xs text-muted-foreground">
          <span className="flex items-center gap-1.5">
            <span className="h-2 w-2 rounded-full bg-teal-400" />New leads
          </span>
          <span className="flex items-center gap-1.5">
            <span className="h-0 w-3 border-t border-dashed border-gray-400" />Average
          </span>
        </div>
      }
      className={className}
      isLoading={isLoading}
      isError={isError}
      isEmpty={!!summary && summary.totalNew === 0}
      emptyMessage="No leads in the last 6 months"
    >
      {/* Descriptive raw aggregations only — a 6-month total, a monthly average, and the current
          calendar month's count. No month-over-month delta / arrow / trend (excluded by design). */}
      <div className="flex flex-wrap items-center gap-x-6 gap-y-1 text-xs">
        <span>
          <span className="font-bold text-foreground">{summary?.totalNew}</span>{" "}
          <span className="text-muted-foreground">New leads · 6 mo</span>
        </span>
        <span>
          <span className="font-bold text-foreground">{summary?.monthlyAverage}</span>{" "}
          <span className="text-muted-foreground">Monthly average</span>
        </span>
        <span>
          <span className="font-bold text-foreground">{summary?.currentMonthCount}</span>{" "}
          <span className="text-muted-foreground">Current Month</span>
        </span>
      </div>
      <BarChart
        data={(points ?? []).map((p) => ({
          label: p.month,
          value: p.value,
          color: p.highlight ? "#10B7B3" : "#4FD8D7",
          highlight: p.highlight,
        }))}
        max={max}
      />
    </ChartCard>
  );
}
