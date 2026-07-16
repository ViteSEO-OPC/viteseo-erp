"use client";

import {
  BarChart as RechartsBar,
  Bar,
  XAxis,
  YAxis,
  Tooltip,
  ResponsiveContainer,
  Cell,
} from "recharts";

type InnerProps = {
  data: { label: string; value: number; color?: string; highlight?: boolean }[];
  horizontal?: boolean;
  max?: number;
};

export default function BarChartInner({ data, horizontal, max }: InnerProps) {
  return (
    <ResponsiveContainer width="100%" height={200}>
      <RechartsBar
        data={data}
        layout={horizontal ? "vertical" : "horizontal"}
        margin={{ top: 4, right: 4, bottom: 4, left: horizontal ? 80 : 4 }}
        barCategoryGap={horizontal ? 8 : "20%"}
      >
        {horizontal ? (
          <XAxis type="number" domain={[0, max ?? 100]} hide />
        ) : (
          <XAxis dataKey="label" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
        )}
        {horizontal ? (
          <YAxis type="category" dataKey="label" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} width={80} />
        ) : (
          <YAxis domain={[0, max ?? 100]} hide />
        )}
        <Tooltip
          contentStyle={{ borderRadius: 8, fontSize: 12, border: "1px solid var(--border)" }}
        />
        <Bar dataKey="value" radius={[4, 4, 4, 4]} maxBarSize={horizontal ? 16 : 32}>
          {data.map((entry, i) => (
            <Cell key={i} fill={entry.color ?? "var(--chart-1)"} />
          ))}
        </Bar>
      </RechartsBar>
    </ResponsiveContainer>
  );
}
