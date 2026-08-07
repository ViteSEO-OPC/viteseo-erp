"use client";

import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import type { WorkloadBar, WorkloadThresholds } from "../../types";
import { CATEGORY_COLORS } from "./shared";

/**
 * One stacked bar per member (or group): their active effort split by task
 * category. The stack total is the number the capacity bands are judged
 * against, so the bar's height and the person's Light/Moderate/Heavy level are
 * always the same figure read two ways.
 *
 * Dashed lines mark where the bands end, which is what makes a tall bar
 * legible as "over capacity" rather than just "taller than the others".
 */
export function WorkloadChart({
  bars,
  thresholds,
  emptyMessage,
}: {
  bars: WorkloadBar[];
  thresholds: WorkloadThresholds;
  emptyMessage: string;
}) {
  if (bars.length === 0) {
    return (
      <div className="flex h-[320px] items-center justify-center text-sm text-ink-soft">
        {emptyMessage}
      </div>
    );
  }

  // Always show the full capacity range so bars stay comparable between
  // renders — a chart that rescales to its tallest bar hides overload.
  const tallest = Math.max(...bars.map((b) => b.total_effort), thresholds.max);

  return (
    <div className="h-[340px] w-full">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart
          data={bars}
          margin={{ top: 8, right: 16, bottom: 8, left: 0 }}
          barCategoryGap="28%"
        >
          <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e5e7eb" />
          <XAxis
            dataKey="name"
            tickLine={false}
            axisLine={false}
            tick={{ fontSize: 12, fill: "#6b7280" }}
            interval={0}
            height={48}
            angle={bars.length > 8 ? -30 : 0}
            textAnchor={bars.length > 8 ? "end" : "middle"}
          />
          <YAxis
            domain={[0, tallest]}
            tickLine={false}
            axisLine={false}
            tick={{ fontSize: 11, fill: "#9ca3af" }}
            width={44}
          />
          <Tooltip
            cursor={{ fill: "rgba(0,0,0,0.03)" }}
            content={({ active, payload }) => {
              if (!active || !payload?.length) return null;
              const bar = payload[0].payload as WorkloadBar;
              return (
                <div className="rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-lg">
                  <p className="font-bold text-ink">{bar.name}</p>
                  <p className="mt-1 text-ink-soft">
                    Total effort <span className="font-bold text-ink">{bar.total_effort}</span>{" "}
                    · {bar.task_count} {bar.task_count === 1 ? "item" : "items"}
                  </p>
                  <p className="text-ink-soft">
                    Light {bar.light} · Moderate {bar.moderate} · Heavy {bar.heavy}
                  </p>
                  <p className="mt-1 font-bold capitalize" style={{ color: LEVEL_COLORS[bar.level] }}>
                    {bar.level} workload
                  </p>
                </div>
              );
            }}
          />
          {(["light", "moderate", "heavy"] as const).map((category) => (
            <Bar key={category} dataKey={category} stackId="effort" fill={CATEGORY_COLORS[category]}>
              {bars.map((b) => (
                <Cell key={b.id} />
              ))}
            </Bar>
          ))}
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

/** Band colours reuse the category palette — same three weights, one scale. */
const LEVEL_COLORS = CATEGORY_COLORS;
