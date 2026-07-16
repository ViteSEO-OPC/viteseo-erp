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

// Live per-BD operational row (GET /dashboard/bd-performance). Only one metric has an
// unambiguous backend source today: `assigned` (a raw leads.assigned_to count). Follow-ups,
// converted leads, conversion rate, and any performance status are intentionally absent —
// there is no follow-ups backend and the lead-status enum has no converted/won state, so the
// card renders those columns as display-only placeholders until the KPI Engine milestone.
export type BdPerformanceRow = {
  id: string;
  name: string;
  initials: string;
  assigned: number;
};

export type ManagementData = {
  range: string;
  kpis: ManagementKpi[];
  // Lead Acquisition (useLeadAcquisition → GET /dashboard/lead-acquisition), BD Performance
  // (useBdPerformance → GET /dashboard/bd-performance), and Recent Activity (useActivityFeed) are
  // all live queries — no longer part of this mock shape.
};
