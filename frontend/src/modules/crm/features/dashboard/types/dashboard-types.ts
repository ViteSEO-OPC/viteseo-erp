import type { WebsiteStatus } from "@/modules/crm/features/leads/types/lead-types";

export type DeltaDirection = "up" | "down" | "flat";
export type DeltaTone = "positive" | "warning" | "neutral";

export type DeltaIndicator = {
  direction: DeltaDirection;
  text: string;
  tone: DeltaTone;
};

export type KpiStat = {
  id: string;
  label: string;
  value: string;
  valueVariant: "neutral" | "accent";
  /** Optional trend chip. Only present when backed by a real trend source (never fabricated). */
  delta?: DeltaIndicator;
  /** Honest, static descriptor shown under the value (no fabricated number/trend). */
  caption?: string;
  /** true → metric awaits a backend dependency; render a Pending badge, no fabricated value. */
  pending?: boolean;
  pendingReason?: string;
  href?: string;
};

export type PriorityLevel = "high" | "medium" | "low";

// Sprint 6: the overview cards segment leads by website_status. `tone` is the existing
// visual slot (high/medium/low colour + chip) preserved unchanged so the UI is identical;
// `websiteStatus` drives the count, copy, and drill-down filter. Type name left as-is for
// the demo sprint (rename deferred with the endpoint/hook/query-key refactor).
export type PriorityBucket = {
  websiteStatus: WebsiteStatus;
  tone: PriorityLevel;
  count: number;
  title: string;
  description: string;
  avgScore: number;
  pipelinePct: number;
};

export type LeadStatus =
  | "interested"
  | "awaiting-reply"
  | "declined"
  | "call-back-req";

export type LeadRow = {
  id: string;
  business: string;
  industry: string;
  score: number;
  priority: PriorityLevel;
  status: LeadStatus;
};

// "won" is the sales-outcome tile (`lead.won`): a converted lead is the one activity a BD scans a
// timeline for, and it previously fell through to the generic "ai" glyph.
export type ActivityType = "ai" | "email" | "call" | "auth" | "note" | "won";

export type ActivityItem = {
  id: string;
  type: ActivityType;
  actor: string;
  action: string;
  object: string;
  timestamp: string;
};
