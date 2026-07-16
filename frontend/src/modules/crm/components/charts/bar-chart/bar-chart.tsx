"use client";

import dynamic from "next/dynamic";

const RechartsBarChart = dynamic(
  () => import("./bar-chart-inner"),
  { ssr: false, loading: () => <div className="h-full min-h-[200px] w-full animate-pulse rounded-lg bg-gray-100" /> },
);

type BarChartProps = {
  data: { label: string; value: number; color?: string; highlight?: boolean }[];
  horizontal?: boolean;
  max?: number;
};

export function BarChart({ data, horizontal, max }: BarChartProps) {
  return <RechartsBarChart data={data} horizontal={horizontal} max={max} />;
}
