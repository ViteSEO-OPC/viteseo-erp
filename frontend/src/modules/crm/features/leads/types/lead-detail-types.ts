import type { LeadRow, PriorityLevel, LeadStatus, ActivityType } from "@/modules/crm/features/dashboard/types/dashboard-types";

export type { PriorityLevel, LeadStatus };

export type LeadDetail = LeadRow & {
  contact: string;
  email: string;
  phone: string;
  industry: string;
  lastActivity: string;
  score: number;
  // Estimated Deal Value (prospect-stage) — NOT revenue. Null until set.
  amount: number | null;
  assignedTo: { id: string; name: string } | null;
  chips: Array<{ type: "priority" | "status" | "website"; value: string }>;
};

export type Note = {
  id: string;
  author: string;
  initials: string;
  timestamp: string;
  text: string;
  self: boolean;
};

// One row of the Lead Details Activity Timeline, mapped from an Activity record on the
// per-lead endpoint (GET /leads/{lead}/activities). `description` is the stored, self-contained
// human string; `type` reuses the shared event→icon/color mapping (`activityIcon`).
export type TimelineEvent = {
  id: string;
  description: string;
  timestamp: string;
  type: ActivityType;
};

// Stable semantic identifier for a Quick Action, decoupled from copy/order. Only "email" is
// wired today (Draft & Send Email); the rest are deferred and remain inert until wired.
export type QuickActionKey = "email" | "call" | "follow-up" | "contacted";

export type QuickAction = {
  id: string;
  key: QuickActionKey;
  icon: string;
  iconTone: "teal" | "indigo" | "amber" | "green";
  title: string;
  subtitle: string;
};
