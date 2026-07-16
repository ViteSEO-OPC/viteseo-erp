"use client";

import dynamic from "next/dynamic";

const RechartsLineChart = dynamic(
  () => import("./line-chart-inner"),
  { ssr: false, loading: () => <div className="h-full min-h-[200px] w-full animate-pulse rounded-lg bg-muted" /> },
);

type LineSeries = {
  name: string;
  data: { month: string; value: number }[];
  color: string;
};

type LineChartProps = {
  series: LineSeries[];
};

export function LineChart({ series }: LineChartProps) {
  return <RechartsLineChart series={series} />;
}
