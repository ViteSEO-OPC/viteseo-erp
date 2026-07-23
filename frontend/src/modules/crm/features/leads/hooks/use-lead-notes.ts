"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { Note } from "@/modules/crm/features/leads/types/lead-detail-types";

// Raw API shape from GET /leads/{lead}/notes (NoteResource). Mapped to the component-facing
// `Note` (text/timestamp) so the existing DiscussionNotes rendering is reused unchanged.
type NoteApi = {
  id: string;
  leadId: string;
  authorId: string | null;
  author: string;
  initials: string;
  body: string;
  self: boolean;
  createdAt: string | null;
};

function mapNote(api: NoteApi): Note {
  return {
    id: api.id,
    author: api.author,
    initials: api.initials,
    timestamp: api.createdAt ?? "",
    text: api.body,
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
    mutationFn: (body: string) =>
      apiClient.post(`/leads/${leadId}/notes`, { body }).then((r) => r.data.data as NoteApi),
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
    mutationFn: ({ id, body }: { id: string; body: string }) =>
      apiClient
        .patch(`/leads/${leadId}/notes/${id}`, { body })
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
