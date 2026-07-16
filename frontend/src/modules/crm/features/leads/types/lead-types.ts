import type { PriorityLevel, LeadStatus } from "@/modules/crm/features/dashboard/types/dashboard-types";

export type { PriorityLevel, LeadStatus };

export type WebsiteStatus = "has-website" | "no-website" | "needs-redesign";

// Human-readable labels for the website_status enum. Centralized here (next to the enum)
// so every surface — Lead Detail chip, Edit drawer, etc. — renders the same wording.
export const WEBSITE_STATUS_LABELS: Record<WebsiteStatus, string> = {
  "has-website": "Has Website",
  "no-website": "No Website",
  "needs-redesign": "Needs Redesign",
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
  // Ownership: the assigned BD. `assignedTo` is present when the API eager-loads it (list/detail).
  assignedToId: string | null;
  assignedTo: { id: string; name: string } | null;
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
  duplicates: ImportDuplicate[];
  errors: ImportRowError[];
  importedCount: number;
};
