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
