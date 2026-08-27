import type {
  EventType,
  PlannerEvent,
} from "@/modules/crm/features/calendar/types/calendar-types";

/**
 * The SINGLE definition of an "actionable outreach item" — reused by the Follow-ups inbox
 * (use-follow-ups-data) and the sidebar Outreach badge so they can never drift.
 *
 * The Outreach inbox is a view over the Planner: it surfaces only the communication-task event types
 * (`follow-up` + Planner `email` Email-Tasks) that are not yet completed.
 * Completed items leave the inbox. Other Planner types (call/meeting/task) stay Planner-only.
 */
const OUTREACH_INBOX_EVENT_TYPES: ReadonlySet<EventType> = new Set(["follow-up", "email"]);

export function isPendingOutreachEvent(event: PlannerEvent): boolean {
  // "Not completed", not a list of the statuses that happen to mean it today. The previous
  // `todo | scheduled | in_progress` set was the same thing written the fragile way: it enumerated
  // the complement of `completed`, so a fifth PlannerEventStatus would have been silently counted
  // as pending here while the server's `status != 'completed'` kept excluding it — the two
  // definitions drifting apart with nothing failing. Behaviour today is identical, because those
  // three ARE every non-completed value of the union.
  return OUTREACH_INBOX_EVENT_TYPES.has(event.eventType) && event.status !== "completed";
}
