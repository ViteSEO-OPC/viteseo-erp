"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { JSONContent } from "@tiptap/react";
import type { Note } from "@/modules/crm/features/leads/types/lead-detail-types";

// Raw API shape from GET /leads/{lead}/notes (NoteResource). Mapped to the component-facing
// `Note` (text/timestamp) so the existing DiscussionNotes rendering is reused unchanged.
type NoteApi = {
  id: string;
  leadId: string;
  authorId: string | null;
  author: string;
  initials: string;
  avatarUrl: string | null;
  body: string;
  /** Tiptap document, or null for a legacy/imported plain-text note. */
  bodyDoc: JSONContent | null;
  self: boolean;
  createdAt: string | null;
};

/** What a write sends. `body` is the plaintext projection; `bodyDoc` the document that produced it. */
type NoteWritePayload = {
  body: string;
  bodyDoc?: JSONContent | null;
};

/**
 * Both fields go on the wire, but only one is authoritative: when `bodyDoc` is present the server
 * DISCARDS the submitted `body` and re-derives it with TiptapDocument::extractText(). `body` is still
 * sent because it is `required` (a plain-text client must keep working) and because the stored column
 * is never null.
 */
function writeBody(payload: NoteWritePayload) {
  return { body: payload.body, body_doc: payload.bodyDoc ?? null };
}

function mapNote(api: NoteApi): Note {
  return {
    id: api.id,
    author: api.author,
    initials: api.initials,
    avatarUrl: api.avatarUrl ?? null,
    timestamp: api.createdAt ?? "",
    text: api.body,
    doc: api.bodyDoc ?? null,
    self: api.self,
  };
}

/** Live internal notes for a lead, chronological (oldest → newest, backend-ordered). */
export function useLeadNotes(leadId: string) {
  const query = useQuery({
    queryKey: queryKeys.leads.notes(leadId),
    queryFn: () =>
      apiClient
        .get(`/leads/${leadId}/notes`)
        .then((r) => (r.data.data as NoteApi[]).map(mapNote)),
    enabled: !!leadId,
  });

  return { notes: query.data, isLoading: query.isLoading, isError: query.isError };
}

/**
 * Invalidation after any note write, scoped to the CURRENT lead only: the lead's notes and its
 * activity timeline (which the `note.*` event lands on). Deliberately does NOT touch the global
 * Dashboard Recent Activity / Activity Logs feeds — those refetch on their own cadence, and a
 * single-lead note write is not worth a broad invalidation.
 */
function useInvalidateNotes(leadId: string) {
  const queryClient = useQueryClient();
  return () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.leads.notes(leadId) });
    queryClient.invalidateQueries({ queryKey: queryKeys.leads.activities(leadId) });
  };
}

/** Create a note. Logs `note.created`. */
export function useCreateNote(leadId: string) {
  const invalidate = useInvalidateNotes(leadId);
  return useMutation({
    mutationFn: (payload: NoteWritePayload) =>
      apiClient
        .post(`/leads/${leadId}/notes`, writeBody(payload))
        .then((r) => r.data.data as NoteApi),
    onSuccess: () => {
      invalidate();
      toast.success("Note added");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Failed to add note")),
  });
}

/** Edit a note (author/Admin/SA — enforced by the backend NotePolicy). Logs `note.updated`. */
export function useUpdateNote(leadId: string) {
  const invalidate = useInvalidateNotes(leadId);
  return useMutation({
    mutationFn: ({ id, ...payload }: NoteWritePayload & { id: string }) =>
      apiClient
        .patch(`/leads/${leadId}/notes/${id}`, writeBody(payload))
        .then((r) => r.data.data as NoteApi),
    onSuccess: () => {
      invalidate();
      toast.success("Note updated");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Failed to update note")),
  });
}

/** Delete a note (author/Admin/SA — enforced by the backend NotePolicy). Logs `note.deleted`. */
export function useDeleteNote(leadId: string) {
  const invalidate = useInvalidateNotes(leadId);
  return useMutation({
    mutationFn: (id: string) => apiClient.delete(`/leads/${leadId}/notes/${id}`),
    onSuccess: () => {
      invalidate();
      toast.success("Note deleted");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Failed to delete note")),
  });
}
