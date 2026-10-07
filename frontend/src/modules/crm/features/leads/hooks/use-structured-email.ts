"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { serializeDocument } from "@/modules/crm/features/email-templates/lib/email-design";
import type { TemplateContent } from "@/modules/crm/features/email-templates/types/email-design-types";

/** A per-lead structured email as the server returns it (EmailResource): a draft until it is sent or scheduled. */
export type StructuredEmail = {
  id: string;
  leadId: string | null;
  to: string;
  subject: string;
  status: string;
  content: TemplateContent;
  emailTemplateId: string | null;
  emailDesignId: string | null;
};

type SaveVars = { id: string; subject: string; emailDesignId: string; content: TemplateContent };

/**
 * Lead Details → New designed email. Starting copies a designed template's content, design and subject onto a new
 * draft ON THE SERVER — the browser only names the template — so the reusable template is never written. Saving,
 * scheduling and sending act on that draft; sending is the existing draft send.
 */
export function useStructuredEmail(leadId: string) {
  const queryClient = useQueryClient();
  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.leads.emails(leadId) });
    // The Outreach drafts list, where the draft is also listed (read-only there).
    queryClient.invalidateQueries({ queryKey: queryKeys.followUps.all });
  };

  const start = useMutation({
    mutationFn: (emailTemplateId: string) =>
      apiClient
        .post(`/leads/${leadId}/emails/structured`, { email_template_id: emailTemplateId })
        .then((r) => r.data.data as StructuredEmail),
    onSuccess: refresh,
  });

  const save = useMutation({
    mutationFn: ({ id, subject, emailDesignId, content }: SaveVars) =>
      apiClient
        .put(`/emails/${id}/structured`, { subject, email_design_id: emailDesignId, content: serializeDocument(content) })
        .then((r) => r.data.data as StructuredEmail),
    onSuccess: refresh,
  });

  const send = useMutation({
    mutationFn: (id: string) => apiClient.post(`/emails/${id}/send`).then((r) => r.data.data as StructuredEmail),
    onSuccess: () => {
      refresh();
      queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
    },
  });

  const schedule = useMutation({
    mutationFn: ({ id, scheduledAt }: { id: string; scheduledAt: string }) =>
      apiClient.post(`/emails/${id}/schedule`, { scheduled_at: scheduledAt }).then((r) => r.data.data as StructuredEmail),
    onSuccess: refresh,
  });

  return { start, save, send, schedule };
}
