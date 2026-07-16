"use client";

import { useState } from "react";
import type { ReportsData } from "@/modules/crm/features/reports/types/reports-types";

const MOCK_DATA: ReportsData = {
  range: "Last 30 Days",
  kpis: [
    { id: "total", label: "Total Leads", value: "248", subCaption: "vs 221 last period", trendDirection: "up", trendPercent: "12%", trendTone: "teal", sparkline: [40, 55, 48, 62, 58, 70, 65, 78, 72, 80, 85, 90] },
    { id: "contacted", label: "Leads Contacted", value: "71%", subCaption: "176 of 248 leads", trendDirection: "up", trendPercent: "8%", trendTone: "teal", progress: { value: 71, target: 100 } },
    { id: "conversion", label: "Conversion Rate", value: "18%", subCaption: "45 converted leads", trendDirection: "down", trendPercent: "2%", trendTone: "red", sparkline: [22, 20, 18, 15, 18, 16, 14, 18, 15, 17, 18, 16] },
    { id: "declined", label: "Declined Leads", value: "42", subCaption: "17% of total leads", trendDirection: "up", trendPercent: "8%", trendTone: "red", sparkline: [28, 32, 30, 35, 38, 36, 40, 42, 38, 41, 39, 42] },
  ],
  statusBars: [
    { label: "New", value: 68, color: "var(--chart-teal, #4FD8D7)" },
    { label: "Confirmed", value: 55, color: "var(--chart-teal-dark, #10B7B3)" },
    { label: "Interested", value: 83, color: "var(--chart-teal-light, #76E9E6)" },
    { label: "Declined", value: 42, color: "var(--chart-2, #F08080)" },
    { label: "Rescheduled", value: 0, color: "#F2F2F2" },
  ],
  priorityDonut: {
    title: "Lead priority breakdown",
    subtitle: "High / Medium / Low segmentation",
    centerLabel: "248 total",
    segments: [
      { label: "High", value: 63, color: "var(--chart-1, #4FD8D7)" },
      { label: "Medium", value: 104, color: "var(--chart-3, #F9C74F)" },
      { label: "Low", value: 81, color: "var(--chart-2, #F08080)" },
    ],
  },
  outcomeDonuts: [
    {
      title: "Interested vs. declined",
      subtitle: "Lead outcome comparison",
      centerLabel: "48%",
      segments: [
        { label: "Interested", value: 48, color: "var(--chart-1, #4FD8D7)" },
        { label: "Declined", value: 52, color: "var(--chart-2, #F08080)" },
      ],
    },
    {
      title: "Email outreach",
      subtitle: "Campaign completion status",
      centerLabel: "62%",
      segments: [
        { label: "Completed", value: 62, color: "var(--chart-1, #4FD8D7)" },
        { label: "In progress", value: 38, color: "var(--chart-gray, #C5C5C5)" },
      ],
    },
    {
      title: "Cold calling",
      subtitle: "Outreach progress",
      centerLabel: "44%",
      segments: [
        { label: "In progress", value: 44, color: "var(--chart-3, #F9C74F)" },
        { label: "Not started", value: 56, color: "var(--chart-gray, #C5C5C5)" },
      ],
    },
  ],
  outreachLine: [
    {
      name: "Email",
      color: "var(--chart-1, #4FD8D7)",
      data: [
        { month: "Feb", value: 80 }, { month: "Mar", value: 95 }, { month: "Apr", value: 90 },
        { month: "May", value: 120 }, { month: "Jun", value: 110 }, { month: "Jul", value: 130 },
      ],
    },
    {
      name: "Cold call",
      color: "var(--chart-teal-dark, #10B7B3)",
      data: [
        { month: "Feb", value: 40 }, { month: "Mar", value: 55 }, { month: "Apr", value: 60 },
        { month: "May", value: 75 }, { month: "Jun", value: 85 }, { month: "Jul", value: 90 },
      ],
    },
  ],
  conversionBars: [
    { month: "Feb", value: 12, color: "var(--chart-4, #B8A9E8)" },
    { month: "Mar", value: 15, color: "var(--chart-4, #B8A9E8)" },
    { month: "Apr", value: 14, color: "var(--chart-4, #B8A9E8)" },
    { month: "May", value: 20, color: "var(--chart-violet-strong, #8069C5)", highlight: true },
    { month: "Jun", value: 18, color: "var(--chart-4, #B8A9E8)" },
    { month: "Jul", value: 18, color: "var(--chart-4, #B8A9E8)" },
  ],
  industryBars: [
    { sector: "Retail", value: 78, color: "var(--chart-1, #4FD8D7)" },
    { sector: "FSB", value: 61, color: "var(--chart-teal-dark, #10B7B3)" },
    { sector: "Healthcare", value: 46, color: "var(--chart-teal-light, #76E9E6)" },
    { sector: "Professional Svs", value: 32, color: "var(--chart-teal-lightest, #A9EFEE)" },
    { sector: "Others", value: 21, color: "var(--chart-gray, #C5C5C5)" },
  ],
};

export function useReportsData() {
  const [data] = useState<ReportsData>(MOCK_DATA);
  const [range, setRange] = useState(data.range);

  return { data, range, setRange };
}
