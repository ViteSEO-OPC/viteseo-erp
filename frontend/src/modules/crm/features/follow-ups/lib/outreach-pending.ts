import type {
  EventType,
  PlannerEvent,
  PlannerEventStatus,
} from "@/modules/crm/features/calendar/types/calendar-types";

/**
 * The SINGLE definition of an "actionable outreach item" — reused by the Follow-ups inbox
 * (use-follow-ups-data) and the sidebar Outreach badge so they can never drift.
 *
 * The Outreach inbox is a view over the Planner: it surfaces only the communication-task event types
 * (`follow-up` + Planner `email` Email-Tasks) that are still pending (todo / scheduled / in_progress).
 * Completed items leave the inbox. Other Planner types (call/meeting/task) stay Planner-only.
 */
const OUTREACH_INBOX_EVENT_TYPES: ReadonlySet<EventType> = new Set(["follow-up", "email"]);

const OUTREACH_PENDING_STATUSES: ReadonlySet<PlannerEventStatus> = new Set([
  "todo",
  "scheduled",
  "in_progress",
]);

export function isPendingOutreachEvent(event: PlannerEvent): boolean {
  return (
    OUTREACH_INBOX_EVENT_TYPES.has(event.eventType) &&
    OUTREACH_PENDING_STATUSES.has(event.status)
  );
}
