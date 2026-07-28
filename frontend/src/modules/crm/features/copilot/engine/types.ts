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
  | "computation";

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
};

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

export type CopilotContext = {
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
