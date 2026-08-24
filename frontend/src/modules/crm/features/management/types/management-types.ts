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
// leads.assigned_to count. `converted` and `rate` reuse the approved Business Computation
// definitions (§3.3 / §3.5): conversion is the existence of a Deal, and the rate's denominator is
// engaged leads — so this card always agrees with Reports & Performance. `rate` is null when the
// BD has no engaged leads; that is "undefined", rendered as an em dash, and must never be shown
// as 0%.
//
// Follow-ups and performance status remain absent: the Planner now models follow-up work, so they
// are no longer blocked by missing data, but by a Product Owner decision on which figure each
// column means. The card renders those two as display-only placeholders until then.
export type BdPerformanceRow = {
  id: string;
  name: string;
  initials: string;
  assigned: number;
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
