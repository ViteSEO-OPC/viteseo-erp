import type { PriorityLevel, LeadStatus } from "@/modules/crm/features/dashboard/types/dashboard-types";

export type { PriorityLevel, LeadStatus };

export type WebsiteStatus =
  | "has-website"
  | "no-website"
  | "needs-redesign"
  | "website-unknown"
  | "website-unreachable";

// Human-readable labels for the website_status enum. Centralized here (next to the enum)
// so every surface — Lead Detail chip, Edit drawer, etc. — renders the same wording.
export const WEBSITE_STATUS_LABELS: Record<WebsiteStatus, string> = {
  "has-website": "Has Website",
  "no-website": "No Website",
  "needs-redesign": "Needs Redesign",
  "website-unknown": "Not Verified",
  "website-unreachable": "Unreachable",
};

export type OutreachStatus = "sent" | "opened" | "replied" | "none";

export type CallingStatus = "called" | "scheduled" | "not-called";

export type Lead = {
  id: string;
  business: string;
  contact: string;
  email: string | null;
  phone: string | null;
  industry: string | null;
  websiteStatus: WebsiteStatus;
  priority: PriorityLevel;
  emailOutreach: OutreachStatus;
  coldCalling: CallingStatus;
  updateStatus: LeadStatus;
  score: number;
  // Estimated Deal Value (prospect-stage) — NOT revenue (revenue lives on the Deal). Null until set.
  amount: number | null;
  // Lead Acquisition Channel — the business "Source" (Referral, Facebook, …). Distinct from the
  // backend `source` provenance field; null until set. No business computation depends on it.
  acquisitionChannel: string | null;
  // Business website URL (leads.website) — the "Website Domain" field. Null until set.
  website: string | null;
  // Digital Presence (social) + Marketplace Presence (e-commerce). All nullable/optional. Plain
  // strings today; ready for clickable links / analytics / outreach / AI enrichment.
  facebook: string | null;
  instagram: string | null;
  tiktok: string | null;
  linkedin: string | null;
  shopee: string | null;
  lazada: string | null;
  tiktokShop: string | null;
  otherMarketplace: string | null;
  // Ownership: the assigned BD. `assignedTo` is present when the API eager-loads it (list/detail).
  assignedToId: string | null;
  assignedTo: { id: string; name: string; suspended?: boolean } | null;
  lastActivity: string;
};

// snake_case payload matching the Laravel CRUD API (create/update).
export type LeadWritePayload = {
  business_name?: string;
  contact_name?: string;
  email?: string | null;
  phone?: string | null;
  industry?: string | null;
  website_status?: WebsiteStatus;
  priority?: PriorityLevel;
  email_outreach?: OutreachStatus;
  cold_calling?: CallingStatus;
  status?: LeadStatus;
  // Estimated Deal Value (prospect-stage). Separate from Deal revenue.
  amount?: number | null;
  // Lead Acquisition Channel — business "Source". Maps to leads.acquisition_channel (NOT leads.source).
  acquisition_channel?: string | null;
  // Website Domain (leads.website) + Digital/Marketplace presence — all nullable/optional.
  website?: string | null;
  /**
   * Create-only: the optional FIRST internal note. Singular — the backend persists it as one
   * `crm_notes` row via the same service the Lead Details composer uses, so it is not a lead column
   * and never appears on a Lead read payload. Later notes go through /leads/{id}/notes.
   */
  note?: string;
  facebook?: string | null;
  instagram?: string | null;
  tiktok?: string | null;
  linkedin?: string | null;
  shopee?: string | null;
  lazada?: string | null;
  tiktok_shop?: string | null;
  other_marketplace?: string | null;
  assigned_to?: number | null;
};

// Shape returned by POST /leads/import (preview + commit share this shape).
export type ImportSummary = {
  total: number;
  valid: number;
  duplicates: number;
  errors: number;
};

export type ImportMappingRow = {
  csvColumn: string;
  crmField: string;
};

export type ImportDuplicate = {
  row: number;
  email: string;
};

export type ImportRowError = {
  row: number;
  messages: string[];
};

export type ImportResult = {
  dryRun: boolean;
  fileName: string;
  summary: ImportSummary;
  mapping: ImportMappingRow[];
  // Named spreadsheet headers that matched no CRM field (Smart Header Recognition). Optional so an
  // older API response without the field still parses. Never blocks the import.
  unknownColumns?: string[];
  duplicates: ImportDuplicate[];
  errors: ImportRowError[];
  importedCount: number;
};
