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
  | "score_leads";

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
