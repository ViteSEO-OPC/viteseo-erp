// "My Performance" (BD-facing) surface types. The frozen Business Computation Specification
// (BD-PERF-COMP-SPEC) is the source of truth for what these will eventually show; this milestone
// wires only the widgets with a legitimate live source and marks the rest as Demo. No business
// rule or formula is expressed in the frontend — see BD-PERF-FE-ARCH-REVIEW §15.

export type PerfKpiIcon =
  | "conversion"
  | "converted"
  | "revenue"
  | "pipeline"
  | "active"
  | "assigned"
  | "followups";

export type PerfKpiValueTone = "accent" | "neutral";

export type PerfKpiCard = {
  id: string;
  label: string;
  icon: PerfKpiIcon;
  value: string;
  valueTone: PerfKpiValueTone;
  /** A plain descriptive caption under the value. (No period-over-period delta chips — those wait on a
   *  real business computation; the delta variant was intentionally removed.) */
  caption?: string;
  /** true → the metric awaits a backend dependency; render a "Pending" badge, never a number. */
  pending?: boolean;
  /** The specific dependency, surfaced on the Pending badge tooltip. */
  pendingReason?: string;
};
