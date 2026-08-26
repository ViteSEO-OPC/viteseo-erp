// Management Dashboard (Admin/SA) — UI foundation types. These are the shapes the future
// backend metrics plug into; the current data is MOCK only (no KPI computation yet).

export type ManagementKpiIcon = "target" | "zap" | "check" | "trending" | "clock";
export type ManagementKpiTone = "teal" | "green" | "amber";

export type ManagementKpi = {
  id: string;
  label: string;
  value: string;
  subtitle: string;
  icon: ManagementKpiIcon;
  tone: ManagementKpiTone;
};

export type AcquisitionPoint = { month: string; value: number; highlight?: boolean };

// Descriptive-only summary for the Lead Acquisition chart. All three are raw aggregations of
// `leads.created_at` counts — a sum, a mean, and the current calendar month's count. There is
// deliberately NO month-over-month delta / growth % / trend (excluded until the KPI Engine).
export type AcquisitionSummary = {
  totalNew: number;
  monthlyAverage: number;
  currentMonthCount: number;
};

// Live per-BD operational row (GET /dashboard/bd-performance). `assigned` is a raw
// leads.assigned_to count. The other three reuse the approved Business Computation definitions
// rather than restating them, so this card always agrees with Reports & Performance:
//   - followUps — the Planner follow-up work the BD COMPLETED this calendar month, i.e.
//     PerformanceSummaryService's `completedFollowUps`, the same figure My Performance shows.
//   - converted / rate — §3.3 and §3.5: conversion is the existence of a Deal, and the rate's
//     denominator is engaged leads.
//
// The two zeroes mean different things and must render differently. `followUps` is a count, so 0
// is a real measurement and shows as "0". `rate` is null when the BD has no engaged leads; that is
// "undefined", rendered as an em dash, and must never be shown as 0%.
//
// Only performance status remains absent, and NOT for want of data: no approved per-BD status
// definition exists, so which value that column carries is a Product Owner decision. The card
// renders it as a display-only placeholder until one is made.
export type BdPerformanceRow = {
  id: string;
  name: string;
  initials: string;
  /** Shared avatar source (signed link from `users.avatar`); null → render initials. */
  avatarUrl: string | null;
  assigned: number;
  followUps: number;
  converted: number;
  rate: number | null;
};

export type ManagementData = {
  range: string;
  kpis: ManagementKpi[];
  // Lead Acquisition (useLeadAcquisition → GET /dashboard/lead-acquisition), BD Performance
  // (useBdPerformance → GET /dashboard/bd-performance), and Recent Activity (useActivityFeed) are
  // all live queries — no longer part of this mock shape.
};
