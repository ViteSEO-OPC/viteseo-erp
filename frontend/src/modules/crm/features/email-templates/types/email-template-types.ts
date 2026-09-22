/**
 * Settings → Email Templates — the shapes returned by GET /settings/email-templates
 * (EmailTemplateResource + the EmailTemplateCatalog `meta`).
 */

import type { TemplateContent } from "@/modules/crm/features/email-templates/types/email-design-types";

/** Derived server-side from the purpose: the Outreach composer's templates, or the n8n automation's. */
export type TemplateKind = "composer" | "automation";

export type EmailTemplate = {
  id: string;
  purpose: string;
  tone: string;
  kind: TemplateKind;
  /** The RAW authored text, placeholders included — never a rendering for a lead. */
  subject: string;
  body: string;
  /** A designed template's own content, or null for a plain-text template. */
  content: TemplateContent | null;
  /** The reusable email design a designed template is shown in; null for a plain-text template. */
  emailDesignId: string | null;
  emailDesignName: string | null;
  isDesigned: boolean;
  isActive: boolean;
  createdAt: string | null;
  updatedAt: string | null;
};

export type TemplatePurposeOption = {
  value: string;
  kind: TemplateKind;
  /** One of this organization's custom purposes (always a composer purpose) rather than a built-in one. */
  custom?: boolean;
  optionId?: string | null;
};

/** A catalog list a custom value can be added to. */
export type TemplateOptionType = "purpose" | "tone";

/** POST /settings/email-template-options → `data`. */
export type CustomTemplateOption = { id: string; type: TemplateOptionType; value: string; createdAt: string | null };

/** GET /settings/email-template-options → `data`: built-in and custom values, custom ones with their usage. */
export type TemplateOptionsCatalog = {
  purposes: (TemplatePurposeOption & { templatesCount: number | null })[];
  tones: { value: string; custom: boolean; optionId: string | null; templatesCount: number | null }[];
};

/** A placeholder the renderer substitutes, with the CRM sample value the preview uses. */
export type TemplateToken = { key: string; label: string; sample: string };

/** The controlled vocabulary, sent alongside the templates so the page never hardcodes it. */
export type EmailTemplateCatalog = {
  purposes: TemplatePurposeOption[];
  /** Built-in tones, then this organization's custom tones. */
  tones: string[];
  /** The organization's custom tones (also in `tones`), so the dropdowns can show them apart. */
  customTones?: { value: string; optionId: string }[];
  tokens: TemplateToken[];
  /** Templates the automation endpoint can resolve — they carry extra protection. */
  automationTemplateIds: string[];
};

export type EmailTemplatePayload = {
  purpose: string;
  tone: string;
  subject: string;
  /** A plain-text template's body. A designed template sends `content` instead; the server derives its body. */
  body?: string;
  /** The content as one JSON-encoded string, or null to make a designed template plain text again. */
  content?: string | null;
  /** The design to show the content in; null or absent means the template's current design, or the default. */
  email_design_id?: string | null;
  is_active: boolean;
};
