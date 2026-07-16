export type TrendDirection = "up" | "down";

export type KpiCard = {
  id: string;
  label: string;
  value: string;
  subCaption: string;
  // Trend/sparkline are OPTIONAL: only rendered when a real period-over-period comparison exists.
  // Reports KPIs sourced from live aggregates omit them (no fabricated trend — house honesty rule).
  trendDirection?: TrendDirection;
  trendPercent?: string;
  trendTone?: "teal" | "red";
  sparkline?: number[];
  progress?: { value: number; target: number };
};

export type DonutSegment = {
  label: string;
  value: number;
  color: string;
};

export type LineSeries = {
  name: string;
  data: { month: string; value: number }[];
  color: string;
};
