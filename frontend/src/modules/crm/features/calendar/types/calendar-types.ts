export type EventType = "email" | "call" | "meeting" | "task" | "follow-up";

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
