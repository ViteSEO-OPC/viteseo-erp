import type {
  ConsultationOutcome,
  EventType,
  PlannerEventStatus,
} from "@/modules/crm/features/calendar/types/calendar-types";

/** Shared per-type presentation (legend, Board card tags). One source for type colors/labels. */
export const EVENT_TYPE_META: Record<EventType, { label: string; dot: string; bg: string; text: string }> = {
  email: { label: "Email", dot: "#4FD8D7", bg: "#E7FAFA", text: "#0F6F6E" },
  call: { label: "Call", dot: "#7B9EF9", bg: "#DAEDF9", text: "#2C4A7C" },
  meeting: { label: "Meeting", dot: "#F9C74F", bg: "#FEF8EE", text: "#8A6D1F" },
  task: { label: "Task", dot: "#F08080", bg: "#FFEEEE", text: "#9B3B3B" },
  "follow-up": { label: "Follow-up", dot: "#A78BFA", bg: "#F1ECFE", text: "#5B3FA6" },
  // Brand teal — a consultation is the highest-value session type, and deliberately distinct from
  // Meeting's amber so the two are never confused at a glance on the Board or calendar.
  consultation: { label: "Consultation", dot: "#14B8A6", bg: "#E0F7F5", text: "#0F5F5C" },
};

/**
 * 🔒 Frozen consultation outcomes (PDR-002 §4b), in the order a BD sees them. `attended` mirrors
 * `ConsultationOutcome::attended()` on the backend — the two must never drift, because it decides
 * what the Successful Consultations KPI counts.
 */
export const CONSULTATION_OUTCOME_META: {
  value: ConsultationOutcome;
  label: string;
  hint: string;
  attended: boolean;
}[] = [
  { value: "successful", label: "Successful", hint: "Held — the client engaged positively", attended: true },
  { value: "not_interested", label: "Not Interested", hint: "Held — the client attended but declined", attended: true },
  { value: "no_show", label: "No Show", hint: "The client did not turn up", attended: false },
  { value: "cancelled", label: "Cancelled", hint: "Called off before it happened", attended: false },
];

/**
 * A consultation is only fully closed for reporting once its real result is recorded. `null` is an
 * INCOMPLETE business state — never read as attendance (frozen: the system must not infer an outcome,
 * e.g. from a connected call). Until the BD records one, the consultation does not count toward the KPI.
 */
export function awaitsConsultationOutcome(event: {
  eventType: string;
  status: string;
  outcome: string | null;
}): boolean {
  return event.eventType === "consultation" && event.status === "completed" && !event.outcome;
}

/** The four Board columns, in order — the `PlannerEvent.status` value each one groups. */
export const BOARD_COLUMNS: { status: PlannerEventStatus; label: string; dot: string }[] = [
  { status: "todo", label: "To Do", dot: "#9CA3AF" },
  { status: "scheduled", label: "Scheduled", dot: "#7B9EF9" },
  { status: "in_progress", label: "In Progress", dot: "#F9C74F" },
  // "Recently Completed", not "Completed": the backend retires a completion from this column 7 days
  // after completed_at, so the plain label overstated what the column holds. The rest lives in the
  // History mode, which has no time limit.
  { status: "completed", label: "Recently Completed", dot: "#4FD8D7" },
];

export function isPlannerStatus(s: string): s is PlannerEventStatus {
  return s === "todo" || s === "scheduled" || s === "in_progress" || s === "completed";
}

/** Neutral gray presentation for a type this build does not recognize. */
const UNKNOWN_EVENT_TYPE_META = { dot: "#9CA3AF", bg: "#F3F4F6", text: "#4B5563" } as const;

/** `"follow-up"` → `"Follow Up"` — a readable label for a type we have no explicit copy for. */
function humanizeEventType(type: string): string {
  return type.replace(/[-_]/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Presentation for an event type, **tolerant of types this build has never heard of**.
 *
 * `PlannerEvent.eventType` is typed `EventType`, but its value arrives from the API at runtime — so the
 * backend can legitimately serve a type a deployed frontend predates (a new type is additive server-side
 * and needs no client release). Indexing {@link EVENT_TYPE_META} directly returns `undefined` for those,
 * and dereferencing it crashes the whole Planner Board rather than degrading one card.
 *
 * Always resolve runtime types through this function; index EVENT_TYPE_META directly ONLY for a
 * hard-coded literal (e.g. the legend lists), where the key is guaranteed at compile time.
 */
export function eventTypeMeta(type: string): { label: string; dot: string; bg: string; text: string } {
  return (
    EVENT_TYPE_META[type as EventType] ?? { ...UNKNOWN_EVENT_TYPE_META, label: humanizeEventType(type) }
  );
}
