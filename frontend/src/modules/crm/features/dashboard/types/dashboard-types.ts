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

/**
 * A Lead Priority Overview card.
 *
 * Segments on `leads.priority` — the canonical, human-set business priority the Leads table
 * renders in its Priority column — so a card's chip and its drill-down filter are the SAME field
 * and can never contradict each other. `priority` drives the count, the copy, the colour/chip and
 * the `?priority=` drill-down; there is no separate `tone`, because the tone IS the priority.
 *
 * Deliberately NOT `website_status` (no-website / needs-redesign / has-website). That column is a
 * machine-derived assessment of web presence written by the enrichment pipeline, not a priority,
 * and the two are independent by design (see PDR-001). It previously fed these cards, which is why
 * a card chipped "High Priority" could list leads whose actual priority was Medium.
 */
export type PriorityBucket = {
  priority: PriorityLevel;
  count: number;
  title: string;
  description: string;
  avgScore: number;
  totalPct: number;
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
  /**
   * The actor's photo, from the shared avatar source. Null whenever there is no CRM user behind
   * the row — a system event, an inbound reply from an external contact, or a user with no photo —
   * in which case the row keeps its event-type icon tile.
   */
  actorAvatarUrl: string | null;
  action: string;
  object: string;
  timestamp: string;
};
