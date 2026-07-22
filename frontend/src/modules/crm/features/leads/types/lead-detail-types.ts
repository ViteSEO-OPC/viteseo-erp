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
  // Digital Presence (social) + Marketplace Presence (e-commerce) + Website Domain. All nullable.
  // Surfaced on Lead Details only when a value exists; ready for clickable links / future analytics.
  website: string | null;
  facebook: string | null;
  instagram: string | null;
  tiktok: string | null;
  linkedin: string | null;
  shopee: string | null;
  lazada: string | null;
  tiktokShop: string | null;
  otherMarketplace: string | null;
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

// One message in the Lead Details Email Thread (read-only), mapped from an Email record on the
// per-lead endpoint (GET /leads/{lead}/emails). Both directions share this shape; the component
// distinguishes inbound vs outbound and picks the sender/recipient label from it. RC2 is read-only —
// reply composition, attachments, and AI summaries are future additions over the same shape.
export type EmailThreadMessage = {
  id: string;
  direction: "inbound" | "outbound";
  subject: string;
  body: string;
  // Delivery/lifecycle state: sent · received · failed · draft.
  status: string;
  // Unified chronological key (inbound receipt / outbound send / row creation).
  timestamp: string;
  // Inbound sender (populated for inbound rows).
  fromName: string | null;
  fromEmail: string | null;
  // Outbound recipient + the CRM user who sent it.
  to: string | null;
  senderName: string | null;
};

// Stable semantic identifier for a Quick Action row, decoupled from copy/order.
// "email" → redirect to Outreach; "follow-up" → open the Schedule Follow-up modal.
export type QuickActionKey = "email" | "follow-up";

export type QuickAction = {
  id: string;
  key: QuickActionKey;
  icon: string;
  iconTone: "teal" | "indigo" | "amber" | "green";
  title: string;
  subtitle: string;
};
