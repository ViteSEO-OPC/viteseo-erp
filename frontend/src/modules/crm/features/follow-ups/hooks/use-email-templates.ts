"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/**
 * A deterministic email template already rendered for a specific lead (RC3 Phase 2). Purpose + Tone
 * key the variant; Subject + Body are the placeholder-substituted result from the backend renderer.
 */
export type EmailTemplateOption = {
  id: string;
  purpose: string;
  tone: string;
  subject: string;
  body: string;
  /**
   * Whether the template is built with the visual designer. An UNEDITED send of a designed template
   * goes out in its design; the design is the template's own (Settings), never chosen per send.
   * Optional so an older payload without the field reads as plain text.
   */
  isDesigned?: boolean;
  /** The name of that design, for the composer's "Designed · {name}" label; null for plain text. */
  designName?: string | null;
};

/**
 * Active email templates rendered for a lead (GET /leads/{lead}/email-templates). Powers the composer's
 * Purpose + Tone dropdowns and the Subject/Body population. No AI — the backend renders deterministic
 * {{token}} placeholders. Disabled when there is no lead (e.g. a follow-up with no linked lead).
 */
export function useEmailTemplates(leadId: string | null) {
  const query = useQuery<{ data: EmailTemplateOption[] }>({
    queryKey: queryKeys.leads.emailTemplates(leadId ?? "none"),
    queryFn: () => apiClient.get(`/leads/${leadId}/email-templates`).then((r) => r.data),
    enabled: !!leadId,
  });

  return { templates: query.data?.data ?? [], isLoading: query.isLoading };
}

/**
 * The composer's per-lead preview of a designed template (GET /leads/{lead}/email-templates/{id}/preview):
 * the HTML this lead would receive if the signed-in BD sent the template unchanged now. `html` is the exact
 * string the send path would hand the provider; when that send would fall back to plain text it is null
 * and `reason` says why.
 */
export type EmailTemplatePreview = {
  html: string | null;
  reason: "template_not_designed" | "design_missing" | "document_invalid" | "html_too_large" | null;
  htmlBytes: number | null;
  maxHtmlBytes: number;
};

/**
 * Fetched only while the preview is open, and fresh each time it opens: the template, its design and the
 * branding logo can all change between two looks, and the preview promises "if I send this now".
 */
export function useEmailTemplatePreview(leadId: string | null, templateId: string | null, enabled: boolean) {
  return useQuery<EmailTemplatePreview>({
    queryKey: [...queryKeys.leads.emailTemplates(leadId ?? "none"), templateId ?? "none", "preview"],
    queryFn: () =>
      apiClient.get(`/leads/${leadId}/email-templates/${templateId}/preview`).then((r) => r.data.data as EmailTemplatePreview),
    enabled: enabled && !!leadId && !!templateId,
    staleTime: 0,
    gcTime: 0,
  });
}
