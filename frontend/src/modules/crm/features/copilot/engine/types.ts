import type { RichTextSegment } from "@/modules/crm/features/copilot/types/copilot-types";

// ── Intent configuration (mirrors data/intents.json) ────────────────────────────────────────
// The engine is fully data-driven: every behaviour below is expressed as JSON and interpreted
// here. Adding a new intent = one JSON entry, no React/engine change (Open/Closed).

// Structured response kinds the engine knows how to render. `text`/`template` are literal copy
// (with {{placeholder}} interpolation + lightweight **bold**/*emphasis* markup); the rest are
// data-backed renderers that read the mock CRM snapshot. Extend by adding a handler in
// response-engine.ts and one union member here — the UI never changes.
export type ResponseType =
  | "text"
  | "template"
  | "navigate"
  | "lead_priority"
  | "followups"
  | "draft_email"
  | "summary"
  | "open_lead"
  | "score_leads"
  // Explain Computation — renders one structured entry from data/computations.json as a consistent
  // formula / numerator / denominator / reasoning block. See ComputationEntry below.
  | "computation"
  // Personal Planner schedule — "do I have a meeting today", "what's on today", overdue work. One
  // handler serves every schedule question; `scope` selects which slice of CopilotSchedule to read.
  | "schedule"
  // Portfolio counts straight from the dashboard KPI payload — "ilang leads ko", "high priority".
  | "lead_stats"
  // One named lead — "kamusta yung ABC", "may follow-up ba ako kay ABC". Needs an entity, which the
  // matcher extracts and the turn resolves through the policy-scoped lead search BEFORE rendering.
  | "lead_lookup"
  // Overdue FOLLOW-UP work, from the CRM's narrower `pendingFollowUps()` set — deliberately not the
  // broader Planner-badge set behind `schedule`. Resolved imperatively by the turn, like lead_lookup.
  | "followups_overdue"
  // "What happened recently" — the caller's own slice of the shared append-only Activity feed.
  // Resolved imperatively by the turn, like the other live lookups.
  | "recent_activity";

// A frontend side-effect. `open_lead` is resolved into a concrete `navigate` by the response
// engine (it substitutes the lead id), so the action engine only ever executes `navigate`.
export type ActionType = "navigate" | "none";

export type ActionConfig = {
  type: ActionType;
  target?: string; // route path, may carry a query string
};

export type ResponseConfig = {
  type: ResponseType;
  text?: string; // literal copy for `text` / `navigate` / `open_lead`
  template?: string; // copy with {{placeholder}} tokens for `template`
  action?: ActionConfig; // optional side-effect; navigational types default one in
  computationId?: string; // `computation` only — id into data/computations.json
  scope?: ScheduleScope; // `schedule` only — which slice of the personal Planner to answer from
  stat?: LeadStat; // `lead_stats` only — which portfolio count to report
  leadAsk?: LeadAsk; // `lead_lookup` only — what is being asked ABOUT the resolved lead
};

/** Which portfolio count a `lead_stats` intent reports. All come from GET /dashboard/kpis. */
export type LeadStat = "total" | "high-priority" | "interested" | "follow-ups-due" | "status-mix";

/** What a `lead_lookup` intent wants to know once the lead is resolved. */
export type LeadAsk =
  | "overview"
  | "follow-up"
  | "meeting"
  | "next-action"
  | "last-contact";

/** Which schedule question is being asked. One per intent; the handler reads the matching slice. */
export type ScheduleScope =
  | "meetings-today"
  | "meetings-week"
  | "meetings-upcoming"
  | "today"
  | "upcoming"
  | "overdue"
  | "todo-today";

// ── Explain Computation ───────────────────────────────────────────────────────────────────────
// Structured explanations of the CRM's derived values, so every "how is this computed?" answer
// renders in the SAME shape instead of hand-written prose that drifts per author. Authored in
// data/computations.json and retrieved through the existing knowledge index (one search path).

/**
 * How trustworthy an entry is, and why. Rendered — the Copilot never presents a stale or absent
 * rule as settled fact.
 *
 * - `documented` — verified in the implementation AND in the KB computation specs.
 * - `implementation-only` — verified in code, but the KB spec predates the change (e.g. the
 *   Consultations rule, redefined twice since the specs were last verified 2026-07-20).
 * - `not-defined` — the CRM does NOT compute this. No formula is shown; `whyNot` explains.
 */
export type ComputationConfidence = "documented" | "implementation-only" | "not-defined";

export type ComputationEntry = {
  id: string;
  label: string; // business name as it appears in the UI
  /**
   * Retrieval question, overriding the default `How is {label} computed?`. Needed whenever the
   * label is not a metric name — "How is Why a lead is assigned to you computed?" reads badly AND
   * competes for the wrong queries, since the question field carries the highest search boost.
   */
  question?: string;
  measures: string; // the question the value answers, in one line
  formula?: string; // omitted when confidence is "not-defined"
  numerator?: string;
  denominator?: string;
  undefinedWhen?: string; // the "undefined is not zero" behaviour, where it applies
  reasoning?: string; // WHY it is defined this way — the business rationale
  caveats?: string[]; // gotchas a user must know to read the number correctly
  surface?: string; // where in the CRM the figure is shown
  route?: string; // link target; MUST be in the action engine's KNOWN_ROUTES to be clickable
  whyNot?: string; // required when confidence is "not-defined"
  confidence: ComputationConfidence;
  verifiedAgainst?: string; // file/symbol the formula was read from — audit trail, never rendered
  keywords: string; // retrieval terms, indexed alongside knowledge.json
};

export type Intent = {
  id: string;
  keywords: string[];
  response: ResponseConfig;
};

export type IntentConfig = {
  fallbackId: string; // intent shown when nothing matches (typically "help")
  intents: Intent[];
};

// ── Mock CRM snapshot (the source for template values + data-backed responses) ───────────────
// Everything the engine renders comes from here — never hardcoded inside intents.json. Supplied
// by useCopilotContext(); pure mock, no backend call (project rule).

// Numeric metrics addressable as {{placeholders}} inside templates.
export type CopilotMetrics = {
  hotLeads: number;
  followUps: number;
  drafts: number;
  todayCalls: number;
  newLeads: number;
  assignedLeads: number;
};

export type CopilotLead = {
  id: string;
  name: string;
  score: number;
  priority: string;
  note: string;
};

export type CopilotFollowUp = {
  name: string;
  company: string;
  status: string;
};

export type CopilotDraft = {
  to: string;
  subject: string;
  body: string;
};

export type CopilotSummary = {
  newLeads: number;
  contacted: number;
  followUps: number;
  conversion: string;
};

export type CopilotScoring = {
  scored: number;
  movedToHigh: number;
};

/**
 * One Planner event, flattened for the Copilot.
 *
 * `timeLabel` is pre-formatted in the business timezone by the context hook, so the response engine
 * never does timezone maths — the CRM has exactly one Manila day-boundary implementation
 * (lib/timezone) and this keeps the Copilot a consumer of it rather than a second one.
 *
 * `eventType` is a plain string, matching the shared CalendarEvent's reasoning: the engine must not
 * need editing every time the Planner adds a type.
 */
export type CopilotScheduleEvent = {
  id: string;
  title: string;
  eventType: string;
  startsAt: string;
  /** e.g. "9:00 AM" — Manila wall-clock, formatted once at the source. */
  timeLabel: string;
  leadName: string | null;
};

/**
 * The signed-in BD's own Planner, bucketed against the SAME Manila day boundary the Planner and the
 * dashboard use (`startOfBusinessTodayUtc`). No new definition of overdue/today/upcoming is created
 * here — the buckets are derived from the one server-scoped `/planner-events` payload the Planner
 * itself renders, filtered by the already-extracted `isActivePlannerEvent` predicate.
 *
 * `available` is false for anyone who is not a BD. The Planner is a personal BD workspace, barred to
 * Admin/SA at the route level on purpose (`allowedRoles: ["crm_bd"]`), so the Copilot must not become
 * a side door into it. When false every bucket is empty and the handler answers with the same
 * management redirect the CRM already gives.
 */
export type CopilotSchedule = {
  available: boolean;
  today: CopilotScheduleEvent[];
  week: CopilotScheduleEvent[];
  upcoming: CopilotScheduleEvent[];
  overdue: CopilotScheduleEvent[];
};

/**
 * Portfolio counts, straight from `GET /dashboard/kpis` — the SAME ownership-scoped payload the BD
 * dashboard and the Management KPI row already render. Not recomputed here: the Copilot quotes the
 * canonical number or says nothing, so it can never disagree with the dashboard beside it.
 *
 * Ungated by role, unlike {@link CopilotSchedule}. The endpoint already scopes per caller — a BD
 * sees their own portfolio, Admin/SA the whole CRM — which is exactly the existing lead permission
 * model. The Planner gate does NOT apply here: lead data is legitimately management-visible.
 */
export type CopilotLeadStats = {
  available: boolean;
  totalLeads: number;
  highPriority: number;
  interested: number;
  /**
   * The WHOLE scoped pending set (overdue + today + upcoming), quoted from the KPI payload. It rides
   * the backend's shared `pendingFollowUps()` definition — the same one behind the Planner sidebar
   * badge and the reports page's three buckets — so this answer can never drift from them.
   */
  followUpsDue: number;
  statusCounts: Record<string, number>;
};

/** One row of the shared Activity feed, flattened for the response engine. */
export type CopilotActivityEntry = {
  description: string;
  /** Pre-formatted by the shared `formatActivityTimestamp`, so the engine never touches a date. */
  at: string;
  leadName: string | null;
};

/** One lead resolved by the policy-scoped search, flattened for the response engine. */
export type CopilotLeadMatch = {
  id: string;
  name: string;
  status: string;
  priority: string;
  websiteStatus: string | null;
  assignedToName: string | null;
  score: number | null;
};

/**
 * The outcome of resolving a named lead. `matches` carries what the SERVER returned for this caller,
 * so "not found" and "not yours" are indistinguishable here by design — a BD asking about another
 * BD's lead gets an empty result, never a permission error that would confirm the lead exists.
 */
export type CopilotLeadResolution = {
  term: string;
  matches: CopilotLeadMatch[];
  /** Open Planner work for the single resolved lead. Empty unless the viewer is a BD — see below. */
  plannerForLead: CopilotScheduleEvent[];
  /**
   * Newest real activity for the lead, from the append-only activity trail — never the stale
   * `leads.last_activity` column. Null when the lead has no recorded activity, which is answered
   * explicitly rather than guessed at.
   */
  lastActivity: { description: string; at: string } | null;
  plannerAvailable: boolean;
};

/** Who is asking, so a personal answer can be gated. */
export type CopilotIdentity = {
  isBd: boolean;
};

export type CopilotContext = {
  identity: CopilotIdentity;
  schedule: CopilotSchedule;
  leadStats: CopilotLeadStats;
  /**
   * Open Planner work keyed by lead id, cut from the SAME events as {@link CopilotSchedule} and gated
   * identically — empty for anyone who is not a BD. Lets "may follow-up ba ako kay ABC" be answered
   * from data already in hand, with no extra request.
   */
  plannerByLead: Record<string, CopilotScheduleEvent[]>;
  /**
   * Set for ONE turn only, by the send pipeline, for a `followups_overdue` question. Null when the
   * read failed — which is answered as "I couldn't read it", never as a zero.
   */
  overdueFollowUps?: number | null;
  /**
   * Set for ONE turn only, for a `recent_activity` question. `null` means the read failed and is
   * answered as such; an EMPTY ARRAY means the feed genuinely holds nothing — the two are never
   * collapsed, because "I couldn't look" and "nothing happened" are different facts.
   */
  recentActivity?: CopilotActivityEntry[] | null;
  /**
   * Set for ONE turn only, by the send pipeline, after it has resolved a named lead through the
   * policy-scoped search. Absent on the base context because it depends on the message, not on
   * render state — which is also what keeps the response engine synchronous and pure.
   */
  leadResolution?: CopilotLeadResolution;
  metrics: CopilotMetrics;
  topLeads: CopilotLead[];
  followUpsList: CopilotFollowUp[];
  draftEmail: CopilotDraft;
  summary: CopilotSummary;
  scoring: CopilotScoring;
};

// ── Engine output ────────────────────────────────────────────────────────────────────────────
// What a response handler yields: rich-text segments (rendered by the EXISTING MessageRichText
// component — no new UI) plus an optional action for the action engine to run.
export type EngineResult = {
  segments: RichTextSegment[];
  action?: ActionConfig;
};
