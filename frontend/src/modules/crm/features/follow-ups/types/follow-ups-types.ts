// The inbox lists follow-up PlannerEvents that are not yet completed, so its status pill mirrors
// the pending PlannerEvent statuses (the `completed` state is filtered out — it leaves the inbox
// via Mark Done). Planner is the single owner of the follow-up lifecycle; this is a view of it.
export type FollowUpStatus = "todo" | "scheduled" | "in_progress";

export type AvatarColor = "teal" | "blue" | "orange" | "purple" | "gray";

export type FollowUpItem = {
  /** PlannerEvent id — the Mark Done / completion target (PATCH /planner-events/{id}). */
  id: string;
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
