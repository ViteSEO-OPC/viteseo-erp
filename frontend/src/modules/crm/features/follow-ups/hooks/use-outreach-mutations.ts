"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";

export type SendEmailVars = {
  leadId: string;
  subject: string;
  body: string;
  /** Optional recipient override; defaults server-side to the lead's email. */
  to?: string;
};

export type SentEmail = {
  id: string;
  leadId: string;
  to: string;
  subject: string;
  status: string;
  provider: string;
  sentAt: string | null;
};

/** A saved outreach draft — an Email row with status=draft (same table as sent emails). */
export type DraftEmail = {
  id: string;
  leadId: string | null;
  to: string;
  subject: string;
  body: string;
  status: string;
  updatedAt: string | null;
};

// Drafts live under the existing follow-ups query namespace (no new key registry needed).
const draftsKey = [...queryKeys.followUps.all, "drafts"] as const;

/**
 * Send an outreach email to a lead via POST /leads/{leadId}/emails — the ONLY thing the
 * frontend knows. The backend chooses the transport (SMTP / Gmail OAuth / Microsoft Graph /
 * Resend / SES), so this hook stays provider-agnostic. On success it refreshes every consumer
 * of the append-only Activity stream via TanStack Query invalidation — it never mutates
 * Dashboard state directly.
 *
 * Future outreach capabilities (draft saving, reply, retry send, schedule send, attachments)
 * are additional mutation hooks in this same file, reusing this exact apiClient + query-key +
 * toast/error architecture — no new mutation infrastructure needed.
 */
export function useSendEmail() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ leadId, subject, body, to }: SendEmailVars) =>
      apiClient
        .post(`/leads/${leadId}/emails`, { subject, body, ...(to ? { to } : {}) })
        .then((r) => r.data.data as SentEmail),
    onSuccess: (email) => {
      // Refresh through invalidation only — the `email.sent` Activity flows to every
      // /activities consumer automatically.
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.recentActivity() }); // Dashboard Recent Activity
      queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all }); // generic /activities feed (Activity Logs page)
      // A future Email History list would add its own invalidation here, following this same pattern.
      toast.success(`Email sent to ${email.to}`);
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to send email"));
    },
  });
}

/** The current user's saved drafts (GET /emails?status=draft). */
export function useDrafts() {
  return useQuery({
    queryKey: draftsKey,
    queryFn: () =>
      apiClient
        .get("/emails", { params: { status: "draft" } })
        .then((r) => r.data.data as DraftEmail[]),
  });
}

/** Save a new draft for a lead (POST /leads/{leadId}/emails/drafts). Does not send. */
export function useSaveDraft() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ leadId, subject, body, to }: SendEmailVars) =>
      apiClient
        .post(`/leads/${leadId}/emails/drafts`, { subject, body, ...(to ? { to } : {}) })
        .then((r) => r.data.data as DraftEmail),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: draftsKey });
      toast.success("Draft saved");
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to save draft"));
    },
  });
}

/** Update an existing draft in place (PATCH /emails/{id}). */
export function useUpdateDraft() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, subject, body, to }: { id: string; subject: string; body: string; to?: string }) =>
      apiClient
        .patch(`/emails/${id}`, { subject, body, ...(to ? { to } : {}) })
        .then((r) => r.data.data as DraftEmail),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: draftsKey });
      toast.success("Draft saved");
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to save draft"));
    },
  });
}

/** Discard a draft (DELETE /emails/{id}). */
export function useDiscardDraft() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => apiClient.delete(`/emails/${id}`).then(() => id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: draftsKey });
      toast.success("Draft discarded");
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to discard draft"));
    },
  });
}

/**
 * Send an existing draft (POST /emails/{id}/send). The backend transitions the SAME Email row
 * draft → sent (no second record) and logs the existing `email.sent` Activity — so this refreshes
 * the same Activity consumers as useSendEmail.
 */
export function useSendDraft() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) =>
      apiClient.post(`/emails/${id}/send`).then((r) => r.data.data as SentEmail),
    onSuccess: (email) => {
      queryClient.invalidateQueries({ queryKey: draftsKey });
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.recentActivity() });
      queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
      toast.success(`Email sent to ${email.to}`);
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to send email"));
    },
  });
}
