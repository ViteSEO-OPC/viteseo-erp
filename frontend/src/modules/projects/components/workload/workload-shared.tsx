"use client";

import { Badge, Card } from "@/core/ui";
import type { WorkloadCategory } from "@/modules/projects/types/workload";

/**
 * Category colours, shared by the KPI dots, the chart stack and the table
 * badges so one task reads the same weight everywhere on the page. Hexes live
 * here (rather than as classes) because Recharts needs literal fills — same
 * reason STATUS_COLORS exists for the donut.
 */
export const CATEGORY_COLORS: Record<WorkloadCategory, string> = {
  light: "#10b981",
  moderate: "#f59e0b",
  heavy: "#ef4444",
};

/** The effort band each category covers, per the KPI card headers. */
export const CATEGORY_RANGE: Record<WorkloadCategory, string> = {
  light: "1-3",
  moderate: "4-7",
  heavy: "8-10",
};

export const CATEGORY_BLURB: Record<WorkloadCategory, string> = {
  light: "Low cognitive load",
  moderate: "Standard execution",
  heavy: "High complexity",
};

const CATEGORY_TONE = {
  light: "success",
  moderate: "warning",
  heavy: "danger",
} as const;

/** Category badge for a single task — graded by its own effort score. */
export function CategoryBadge({ category }: { category: WorkloadCategory }) {
  return (
    <Badge tone={CATEGORY_TONE[category]} className="uppercase">
      {category}
    </Badge>
  );
}

/** Effort as "9 / 10", tinted by the category it falls in. */
export function EffortCell({
  score,
  category,
  max,
}: {
  score: number;
  category: WorkloadCategory;
  max: number;
}) {
  return (
    <span className="whitespace-nowrap">
      <span className="font-bold" style={{ color: CATEGORY_COLORS[category] }}>
        {score}
      </span>
      <span className="text-ink-faint"> / {max}</span>
    </span>
  );
}

/**
 * The four headline cards: total active work, then a count per task category.
 *
 * "Active" is load you are still carrying — To Do and In Progress. Finished
 * work is counted separately by the member page's completion panel.
 */
export function WorkloadKpiCards({
  totals,
}: {
  totals: { total: number; light: number; moderate: number; heavy: number };
}) {
  const cards: {
    key: string;
    label: string;
    value: number;
    blurb: string;
    dot?: string;
  }[] = [
    {
      key: "total",
      label: "Total Tasks",
      value: totals.total,
      blurb: "Active in current sprint",
    },
    ...(["light", "moderate", "heavy"] as const).map((c) => ({
      key: c,
      label: `${c[0].toUpperCase()}${c.slice(1)} Tasks (${CATEGORY_RANGE[c]})`,
      value: totals[c],
      blurb: CATEGORY_BLURB[c],
      dot: CATEGORY_COLORS[c],
    })),
  ];

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      {cards.map((c) => (
        <Card key={c.key} className="gap-1 p-6">
          <div className="flex items-start justify-between gap-2">
            <p className="text-xs font-bold uppercase tracking-wide text-ink-soft">
              {c.label}
            </p>
            {c.dot && (
              <span
                className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full"
                style={{ backgroundColor: c.dot }}
                aria-hidden
              />
            )}
          </div>
          <p className="text-3xl font-bold text-ink">{c.value}</p>
          <p className="text-sm text-ink-soft">{c.blurb}</p>
        </Card>
      ))}
    </div>
  );
}

/** Legend shared by the bar chart and the donut. */
export function CategoryLegend() {
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
      {(["light", "moderate", "heavy"] as const).map((c) => (
        <span key={c} className="flex items-center gap-1.5 text-xs text-ink-soft">
          <span
            className="h-2.5 w-2.5 rounded-full"
            style={{ backgroundColor: CATEGORY_COLORS[c] }}
            aria-hidden
          />
          {c[0].toUpperCase()}
          {c.slice(1)}
        </span>
      ))}
    </div>
  );
}
