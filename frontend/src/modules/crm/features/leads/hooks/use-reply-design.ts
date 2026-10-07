"use client";

import { useMutation, useQuery } from "@tanstack/react-query";
import type { JSONContent } from "@tiptap/react";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/** One Email Design a reply can be sent in — an id and a name, never a layout. */
export type EmailDesignOption = {
  id: string;
  name: string;
  isDefault: boolean;
};

/** The HTML a reply would be sent as in the chosen design, exactly as the send renders it. */
export type ReplyDesignPreview = {
  html: string;
  htmlBytes: number;
};

/**
 * This organization's live Email Designs, for Lead Details → Reply's design picker
 * (GET /email-designs/options). Read-only: designs are created and edited only in Settings.
 */
export function useEmailDesignOptions() {
  return useQuery<EmailDesignOption[]>({
    queryKey: queryKeys.emailDesigns.options(),
    queryFn: () => apiClient.get("/email-designs/options").then((r) => r.data.data as EmailDesignOption[]),
  });
}

/**
 * Render the current reply in the chosen design (POST /leads/{lead}/emails/design-preview). The server renders
 * it through the same call the send makes, so the preview is the sent HTML. A mutation rather than a query:
 * it renders the document as it is the moment Preview is opened, writes nothing, and is never cached.
 */
export function useReplyDesignPreview() {
  return useMutation({
    mutationFn: ({ leadId, emailDesignId, bodyDoc }: { leadId: string; emailDesignId: string; bodyDoc: JSONContent }) =>
      apiClient
        .post(`/leads/${leadId}/emails/design-preview`, { email_design_id: emailDesignId, body_doc: bodyDoc })
        .then((r) => r.data.data as ReplyDesignPreview),
  });
}
