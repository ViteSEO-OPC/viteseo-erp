export type EventType = "email" | "call" | "meeting" | "task" | "follow-up" | "consultation";

/**
 * 🔒 Frozen business result of a concluded consultation (PDR-002 §4b) — a separate axis from `status`.
 * `successful`/`not_interested` mean the lead ATTENDED (the KPI counts them); `no_show`/`cancelled`
 * mean it never happened. `null` is an INCOMPLETE state, never an assumption of attendance: the BD
 * must record the real result before the consultation counts for reporting.
 */
export type ConsultationOutcome = "successful" | "not_interested" | "no_show" | "cancelled";

// ── Planner live data model (mirrors the API's PlannerEventResource) ─────────────────────────
// The single source of truth shared by every Planner view. The grid's `CalendarEvent` (in
// components/shared/calendar-grid) and `UpcomingItem` below are view-adapter shapes derived from
// this.

export type PlannerEventStatus = "todo" | "scheduled" | "in_progress" | "completed";
export type PlannerEventPriority = "low" | "medium" | "high";

export type PlannerEventLead = {
  id: string;
  business: string | null;
  contact: string | null;
  industry: string | null;
};

export type PlannerEventOwner = {
  id: string;
  name: string;
};

export type PlannerEvent = {
  id: string;
  title: string;
  eventType: EventType;
  status: PlannerEventStatus;
  /** Consultation only; null for every other type, and null while a consultation is unresolved. */
  outcome: ConsultationOutcome | null;
  priority: PlannerEventPriority;
  /** ISO8601, or null for a Board-only backlog item (no scheduled date). */
  startsAt: string | null;
  endsAt: string | null;
  allDay: boolean;
  reminderMinutes: number | null;
  source: string;
  leadId: string | null;
  lead: PlannerEventLead | null;
  dealId: string | null;
  companyId: string | null;
  campaignId: string | null;
  assignedToId: string | null;
  owner: PlannerEventOwner | null;
  description: string | null;
  createdAt: string | null;
  updatedAt: string | null;
};

export type UpcomingItem = {
  id: string;
  date: string;
  weekday: string;
  day: number;
  type: EventType;
  title: string;
  time: string;
  isToday: boolean;
};
