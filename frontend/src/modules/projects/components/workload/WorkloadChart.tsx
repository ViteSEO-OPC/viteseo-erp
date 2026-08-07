"use client";

import {
  Bar,
  BarChart,
  CartesianGrid,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import type { WorkloadBar, WorkloadThresholds } from "../../types";
import { CATEGORY_COLORS } from "./shared";

/**
 * Axis labels are abbreviated ("Leo Santos" → "Leo S.") so a dozen bars stay
 * legible without rotating the text into a diagonal mess. The full name is
 * always one hover away in the tooltip, and group names — which are short and
 * not person-shaped — are left alone beyond a length cap.
 */
function shortLabel(name: string): string {
  const parts = name.trim().split(/\s+/);

  if (parts.length >= 2) {
    const [first, ...rest] = parts;
    const initial = rest[rest.length - 1]?.[0];
    const abbreviated = initial ? `${first} ${initial}.` : first;
    // A very long single forename still needs clipping.
    return abbreviated.length > 14 ? `${abbreviated.slice(0, 13)}…` : abbreviated;
  }

  return name.length > 14 ? `${name.slice(0, 13)}…` : name;
}

/** Below this each bar gets too thin to read, so the chart scrolls instead. */
const MIN_BAR_SLOT = 78;

/**
 * One stacked bar per member (or group): their active effort split by task
 * category. The stack total is the number the capacity bands are judged
 * against, so a bar's height and that person's Light/Moderate/Heavy level are
 * the same figure read two ways.
 *
 * Responsiveness: the plot keeps a minimum width per bar and scrolls
 * horizontally when the container can't fit them all. Squeezing twelve bars
 * into a phone would leave slivers with unreadable labels; scrolling keeps every
 * bar the same legible width at any viewport. Height steps down on small
 * screens so the chart doesn't eat the fold.
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
      <div className="flex h-[220px] items-center justify-center px-4 text-center text-sm text-ink-soft sm:h-[300px]">
        {emptyMessage}
      </div>
    );
  }

  // Always plot against the full capacity range so bars stay comparable between
  // renders — a chart that rescales to its tallest bar hides overload. Headroom
  // is added only when someone has genuinely exceeded the maximum.
  const tallest = Math.max(...bars.map((b) => b.total_effort), thresholds.max);

  const data = bars.map((b) => ({ ...b, label: shortLabel(b.name) }));

  return (
    <div className="-mx-1 overflow-x-auto px-1">
      <div
        className="h-[240px] sm:h-[300px] lg:h-[340px]"
        style={{ minWidth: `${Math.max(bars.length * MIN_BAR_SLOT + 56, 300)}px` }}
      >
        <ResponsiveContainer width="100%" height="100%">
          <BarChart
            data={data}
            margin={{ top: 8, right: 12, bottom: 0, left: 0 }}
            barCategoryGap="26%"
          >
            <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e5e7eb" />
            <XAxis
              dataKey="label"
              tickLine={false}
              axisLine={false}
              tick={{ fontSize: 12, fill: "#6b7280" }}
              interval={0}
              height={32}
              tickMargin={8}
            />
            <YAxis
              domain={[0, tallest]}
              tickLine={false}
              axisLine={false}
              tick={{ fontSize: 11, fill: "#9ca3af" }}
              width={40}
            />

            {/* Where the bands end — what makes a tall bar read as "over
                capacity" rather than merely taller than its neighbours. */}
            <ReferenceLine
              y={thresholds.light}
              stroke={CATEGORY_COLORS.moderate}
              strokeDasharray="4 4"
              strokeOpacity={0.5}
            />
            <ReferenceLine
              y={thresholds.moderate}
              stroke={CATEGORY_COLORS.heavy}
              strokeDasharray="4 4"
              strokeOpacity={0.5}
            />

            <Tooltip
              cursor={{ fill: "rgba(0,0,0,0.03)" }}
              content={({ active, payload }) => {
                if (!active || !payload?.length) return null;
                const bar = payload[0].payload as WorkloadBar;
                return (
                  <div className="max-w-[220px] rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-lg">
                    {/* The unabbreviated name — the axis only had room for "Leo S." */}
                    <p className="font-bold text-ink">{bar.name}</p>
                    <p className="mt-1 text-ink-soft">
                      Total effort{" "}
                      <span className="font-bold text-ink">{bar.total_effort}</span> ·{" "}
                      {bar.task_count} {bar.task_count === 1 ? "item" : "items"}
                    </p>
                    <p className="text-ink-soft">
                      Light {bar.light} · Moderate {bar.moderate} · Heavy {bar.heavy}
                    </p>
                    <p
                      className="mt-1 font-bold capitalize"
                      style={{ color: CATEGORY_COLORS[bar.level] }}
                    >
                      {bar.level} workload
                    </p>
                  </div>
                );
              }}
            />

            {(["light", "moderate", "heavy"] as const).map((category, i, all) => (
              <Bar
                key={category}
                dataKey={category}
                stackId="effort"
                fill={CATEGORY_COLORS[category]}
                maxBarSize={56}
                // Round only the top of the stack.
                radius={i === all.length - 1 ? [4, 4, 0, 0] : undefined}
              />
            ))}
          </BarChart>
        </ResponsiveContainer>
      </div>
    </div>
  );
}
