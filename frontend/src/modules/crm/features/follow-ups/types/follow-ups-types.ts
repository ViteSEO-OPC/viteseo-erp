import type { EventType } from "@/modules/crm/features/calendar/types/calendar-types";

// The inbox lists follow-up PlannerEvents that are not yet completed, so its status pill mirrors
// the pending PlannerEvent statuses (the `completed` state is filtered out — it leaves the inbox
// via Mark Done). Planner is the single owner of the follow-up lifecycle; this is a view of it.
export type FollowUpStatus = "todo" | "scheduled" | "in_progress";

export type AvatarColor = "teal" | "blue" | "orange" | "purple" | "gray";

export type FollowUpItem = {
  /** PlannerEvent id — the Mark Done / completion target (PATCH /planner-events/{id}). */
  id: string;
  /**
   * The Planner event_type this row represents. The inbox surfaces `follow-up` and `email` (Email
   * Task). Used to auto-complete ONLY an Email Task on a successful send (see follow-ups-workspace).
   */
  eventType: EventType;
  /** Linked lead the outreach email targets; null for a follow-up not tied to a lead. */
  leadId: string | null;
  initials: string;
  avatarColor: AvatarColor;
  name: string;
  company: string;
  timestamp: string;
  status: FollowUpStatus;
  preview: string;
  email: string;
  /**
   * True when this row is a lead context injected by the ?lead= deep-link rather than a real
   * pending follow-up PlannerEvent (the lead has no follow-up in the inbox). It has no event id,
   * so completion actions (Mark Done) do not apply — sending/drafting still target `leadId`.
   */
  isVirtual?: boolean;
};

export type EmailDraft = {
  purpose: string;
  tone: string;
  subject: string;
  body: string;
  aiSuggestion: string;
};

export type SchedulerState = {
  enabled: boolean;
  datetime: string;
};
