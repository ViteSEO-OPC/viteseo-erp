"use client";

import { cn } from "@/modules/crm/lib/utils";
import { Card, CardContent } from "@/modules/crm/components/ui/card";
import type { KpiCard } from "@/modules/crm/features/reports/types/reports-types";

type StatCardChartProps = {
  card: KpiCard;
};

export function StatCardChart({ card }: StatCardChartProps) {
  return (
    <Card className="border-border shadow-card">
      <CardContent className="flex flex-col gap-2 pt-4">
        <span className="text-xs font-semibold uppercase tracking-[0.08em] text-gray-500">
          {card.label}
        </span>
        <span className="text-display font-extrabold leading-none text-gray-900">
          {card.value}
        </span>
        <span className="text-xs text-gray-500">{card.subCaption}</span>

        <div className="mt-1 flex items-center gap-2">
          <span
            className={cn(
              "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium",
              card.trendTone === "teal"
                ? "bg-teal-400/10 text-teal-900"
                : "bg-red-500/10 text-red-500",
            )}
          >
            {card.trendDirection === "up" ? "↑" : "↓"} {card.trendPercent}
          </span>
        </div>

        {card.sparkline && (
          <div className="mt-2 flex items-end gap-0.5">
            {card.sparkline.map((v, i) => (
              <span
                key={i}
                className="w-1.5 rounded-t-sm"
                style={{
                  height: `${(v / Math.max(...card.sparkline!)) * 32}px`,
                  backgroundColor: card.id === "declined" ? "var(--chart-2)" : "var(--chart-1)",
                }}
              />
            ))}
          </div>
        )}

        {card.progress && (
          <div className="mt-2">
            <div className="h-2 rounded-full bg-gray-150">
              <div
                className="h-2 rounded-full bg-teal-400 transition-all"
                style={{ width: `${(card.progress.value / card.progress.target) * 100}%` }}
              />
            </div>
            <span className="mt-1 text-[10px] text-gray-500">
              {card.progress.value}% … {card.progress.target}%
            </span>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
