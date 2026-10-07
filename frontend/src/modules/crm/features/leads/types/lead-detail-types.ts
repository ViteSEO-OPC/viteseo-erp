import type { JSONContent } from "@tiptap/react";
import type { LeadRow, ActivityType } from "@/modules/crm/features/dashboard/types/dashboard-types";
import type { LeadOutcome } from "@/modules/crm/features/leads/components/lead-score-ring";

/**
 * The lead's Won conversion, projected read-only from its Deal (GET /leads/{id}). The Deal remains
 * the single source of truth for conversion and revenue — nothing here is editable, and `amount` is
 * REALIZED revenue, distinct from `LeadDetail.amount` (the prospect-stage estimate).
 */
export type LeadDeal = {
  id: string;
  amount: number;
  convertedAt: string | null;
};

export type LeadDetail = LeadRow & {
  contact: string;
  email: string;
  phone: string;
  industry: string;
  lastActivity: string;
  score: number;
  // Estimated Deal Value (prospect-stage) — NOT revenue. Null until set.
  amount: number | null;
  // The Won conversion, or null when this lead has no Deal. `deal !== null` IS "is won" — there is
  // no separate flag, so the badge and the disabled Won button can never disagree with the Deal.
  deal: LeadDeal | null;
  // Whether a `lead.lost` Activity exists. Historical, never a stored state: Lost is append-only
  // (no column, no status change), so this is monotonic — once true it stays true. Distinct from
  // `outcome` below, which resolves Won FIRST and so reports "won" for a lead that is both.
  lost: boolean;
  // Resolved sales outcome for presentation (the Lead Score ring). Derived, never stored: Won comes
  // from the Deal. Null while the lead is still active/unresolved.
  outcome: LeadOutcome | null;
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
  /** Shared avatar source (signed link from `users.avatar`); null → render initials. */
  avatarUrl: string | null;
  timestamp: string;
  /**
   * The note's readable text. ALWAYS present — for a rich note it is the server's projection of
   * `doc`, so search, exports and any text-only surface keep working regardless of format.
   */
  text: string;
  /**
   * The Tiptap document, when this note was composed in the rich editor. `null` for every legacy and
   * imported note, and that nullability IS the format discriminator: null → render `text` as plain
   * text exactly as before; present → render the document read-only.
   */
  doc: JSONContent | null;
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

// One message in the Lead Details Email Thread, mapped from an Email record on the per-lead
// endpoint (GET /leads/{lead}/emails). Both directions share this shape; the component
// distinguishes inbound vs outbound and picks the sender/recipient labels from it. Replying is a
// send through the existing Outreach pipeline and reads nothing extra from this shape —
// attachments and AI summaries remain future additions over it.
export type EmailThreadMessage = {
  id: string;
  direction: "inbound" | "outbound";
  subject: string;
  body: string;
  // A formatted reply's Tiptap document, rendered read-only; null for every plain-text, inbound and
  // older email, which render `body` exactly as before.
  bodyDoc: JSONContent | null;
  // An HTML part shown in a sandboxed frame: a received email's, or the exact HTML a designed email
  // the CRM sent went out with. Null when the email has none, which renders `body` as plain text.
  bodyHtml: string | null;
  // The Email Design a reply was sent in, when the BD chose one: its `bodyHtml` is then what the recipient
  // read, so the thread shows that rather than the document. Null for every other email.
  emailDesignId: string | null;
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
