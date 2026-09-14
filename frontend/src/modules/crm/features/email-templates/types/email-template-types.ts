/**
 * Settings → Email Templates — the shapes returned by GET /settings/email-templates
 * (EmailTemplateResource + the EmailTemplateCatalog `meta`).
 */

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
  isActive: boolean;
  createdAt: string | null;
  updatedAt: string | null;
};

export type TemplatePurposeOption = { value: string; kind: TemplateKind };

/** A placeholder the renderer substitutes, with the CRM sample value the preview uses. */
export type TemplateToken = { key: string; label: string; sample: string };

/** The controlled vocabulary, sent alongside the templates so the page never hardcodes it. */
export type EmailTemplateCatalog = {
  purposes: TemplatePurposeOption[];
  tones: string[];
  tokens: TemplateToken[];
  /** Templates the automation endpoint can resolve — they carry extra protection. */
  automationTemplateIds: string[];
};

export type EmailTemplatePayload = {
  purpose: string;
  tone: string;
  subject: string;
  body: string;
  is_active: boolean;
};
