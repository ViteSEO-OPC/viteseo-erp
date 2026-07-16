"use client";

import dynamic from "next/dynamic";

const RechartsPieChart = dynamic(
  () => import("./pie-chart-inner"),
  { ssr: false, loading: () => <div className="h-full min-h-[180px] w-full animate-pulse rounded-lg bg-muted" /> },
);

type Segment = {
  label: string;
  value: number;
  color: string;
};

type PieChartProps = {
  segments: Segment[];
  centerLabel?: string;
  donut?: boolean;
  showLegend?: boolean;
};

export function PieChart({ segments, centerLabel, donut = true, showLegend = true }: PieChartProps) {
  return <RechartsPieChart segments={segments} centerLabel={centerLabel} donut={donut} showLegend={showLegend} />;
}
