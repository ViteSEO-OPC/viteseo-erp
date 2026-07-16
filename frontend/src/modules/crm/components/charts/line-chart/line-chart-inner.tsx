"use client";

import {
  LineChart as RechartsLine,
  Line,
  XAxis,
  YAxis,
  Tooltip,
  ResponsiveContainer,
  Legend,
} from "recharts";

type InnerProps = {
  series: { name: string; data: { month: string; value: number }[]; color: string }[];
};

export default function LineChartInner({ series }: InnerProps) {
  const months = series[0]?.data.map((d) => d.month) ?? [];
  const combined = months.map((month) => {
    const entry: Record<string, string | number> = { month };
    for (const s of series) {
      const point = s.data.find((d) => d.month === month);
      entry[s.name] = point?.value ?? 0;
    }
    return entry;
  });

  return (
    <ResponsiveContainer width="100%" height={200}>
      <RechartsLine data={combined} margin={{ top: 4, right: 4, bottom: 4, left: 4 }}>
        <XAxis dataKey="month" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
        <YAxis domain={[0, 150]} tick={{ fontSize: 11 }} axisLine={false} tickLine={false} width={30} />
        <Tooltip
          contentStyle={{ borderRadius: 8, fontSize: 12, border: "1px solid var(--border)" }}
        />
        <Legend
          wrapperStyle={{ fontSize: 11, paddingTop: 8 }}
          iconType="circle"
          iconSize={8}
        />
        {series.map((s) => (
          <Line
            key={s.name}
            type="monotone"
            dataKey={s.name}
            stroke={s.color}
            strokeWidth={2}
            dot={{ r: 3, fill: s.color }}
            activeDot={{ r: 5 }}
          />
        ))}
      </RechartsLine>
    </ResponsiveContainer>
  );
}
