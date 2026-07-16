export type TrendDirection = "up" | "down";

export type KpiCard = {
  id: string;
  label: string;
  value: string;
  subCaption: string;
  trendDirection: TrendDirection;
  trendPercent: string;
  trendTone: "teal" | "red";
  sparkline?: number[];
  progress?: { value: number; target: number };
};

export type StatusBarItem = {
  label: string;
  value: number;
  color: string;
};

export type DonutSegment = {
  label: string;
  value: number;
  color: string;
};

export type DonutChart = {
  title: string;
  subtitle: string;
  centerLabel: string;
  segments: DonutSegment[];
};

export type LineSeries = {
  name: string;
  data: { month: string; value: number }[];
  color: string;
};

export type ConversionBar = {
  month: string;
  value: number;
  color: string;
  highlight?: boolean;
};

export type IndustryBar = {
  sector: string;
  value: number;
  color: string;
};

export type ReportsData = {
  kpis: KpiCard[];
  statusBars: StatusBarItem[];
  priorityDonut: DonutChart;
  outcomeDonuts: DonutChart[];
  outreachLine: LineSeries[];
  conversionBars: ConversionBar[];
  industryBars: IndustryBar[];
  range: string;
};
