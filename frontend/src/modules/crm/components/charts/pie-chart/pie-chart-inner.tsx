"use client";

import { PieChart as RechartsPie, Pie, Cell, ResponsiveContainer } from "recharts";

type InnerProps = {
  segments: { label: string; value: number; color: string }[];
  centerLabel?: string;
  donut?: boolean;
  showLegend?: boolean;
};

export default function PieChartInner({ segments, centerLabel, donut = true, showLegend = true }: InnerProps) {
  const total = segments.reduce((s, seg) => s + seg.value, 0);

  return (
    <div className="relative flex items-center justify-center">
      <ResponsiveContainer width={180} height={180}>
        <RechartsPie>
          <Pie
            data={segments}
            dataKey="value"
            nameKey="label"
            cx="50%"
            cy="50%"
            innerRadius={donut ? 50 : 0}
            outerRadius={80}
            paddingAngle={2}
            strokeWidth={0}
          >
            {segments.map((seg, i) => (
              <Cell key={i} fill={seg.color} />
            ))}
          </Pie>
        </RechartsPie>
      </ResponsiveContainer>
      {centerLabel && donut && (
        <span className="pointer-events-none absolute text-sm font-bold text-foreground">
          {centerLabel}
        </span>
      )}
      {showLegend && (
        <div className="ml-3 flex flex-col gap-1.5">
          {segments.map((seg, i) => {
            const pct = total > 0 ? Math.round((seg.value / total) * 100) : 0;
            return (
              <div key={i} className="flex items-center gap-2 text-xs text-secondary-foreground">
                <span
                  className="h-2.5 w-2.5 rounded-full shrink-0"
                  style={{ backgroundColor: seg.color }}
                  aria-hidden
                />
                <span className="whitespace-nowrap">
                  {seg.label} {seg.value} ({pct}%)
                </span>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
