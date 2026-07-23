// Component-facing Call types — a 1:1 mirror of the backend CallResource (camelCase JSON), following
// the PlannerEvent direct-mirror convention. NO business logic lives here: the disposition policy,
// single-active enforcement, and duration are owned by the backend CallSessionService. The metadata
// below (labels, tones, duration formatting) is presentation only.

export type CallStatus = "in_progress" | "completed";
export type CallOutcome =
  | "connected"
  | "no_answer"
  | "busy"
  | "voicemail"
  | "cancelled"
  | "failed";
export type CallDirection = "outbound" | "inbound";

export type CallAgent = { id: string; name: string };

export type Call = {
  id: string;
  plannerEventId: string | null;
  leadId: string;
  direction: CallDirection;
  provider: string;
  status: CallStatus;
  outcome: CallOutcome | null;
  startedAt: string | null;
  endedAt: string | null;
  durationSeconds: number | null;
  notes: string | null;
  agentId: string | null;
  agent: CallAgent | null;
  createdAt: string | null;
  updatedAt: string | null;
};

// The dispositions a BD can pick when COMPLETING a call. `cancelled` is intentionally absent — it is
// the separate Discard action, which the backend rejects on /complete (Rule::enum()->except), so it
// can never be a completion outcome. This list mirrors that server rule; it does not define it.
export const COMPLETION_OUTCOMES: readonly CallOutcome[] = [
  "connected",
  "no_answer",
  "busy",
  "voicemail",
  "failed",
] as const;

type OutcomeTone = "success" | "warning" | "muted" | "danger";

// Display label + semantic tone for every outcome. Tone maps to design tokens in the badge (§ below),
// so both light and dark themes are covered without per-component `dark:` overrides.
export const OUTCOME_META: Record<CallOutcome, { label: string; tone: OutcomeTone }> = {
  connected: { label: "Connected", tone: "success" },
  no_answer: { label: "No Answer", tone: "warning" },
  busy: { label: "Busy", tone: "warning" },
  voicemail: { label: "Voicemail", tone: "muted" },
  cancelled: { label: "Cancelled", tone: "muted" },
  failed: { label: "Failed", tone: "danger" },
};

// Token-based tint per tone (auto-flips for dark via the theme's semantic color tokens).
export const OUTCOME_TONE_CLASS: Record<OutcomeTone, string> = {
  success: "bg-success-subtle text-success",
  warning: "bg-warning-subtle text-warning",
  muted: "bg-muted text-muted-foreground",
  danger: "bg-destructive/10 text-destructive",
};

/** Human call length: "2h 1m", "3m 20s", "45s", or "—" when unknown/zero. Presentation only. */
export function formatCallDuration(seconds: number | null | undefined): string {
  if (seconds == null || seconds <= 0) return "—";
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = seconds % 60;
  if (hours > 0) return `${hours}h ${minutes}m`;
  if (minutes > 0) return secs > 0 ? `${minutes}m ${secs}s` : `${minutes}m`;
  return `${secs}s`;
}
