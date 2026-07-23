"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { Call, CallOutcome } from "@/modules/crm/features/leads/types/call-types";

const fetchCalls = (leadId: string) =>
  apiClient.get(`/leads/${leadId}/calls`).then((r) => r.data.data as Call[]);

/** Full Call History for a lead (GET /leads/{lead}/calls) — newest-first (backend-ordered). */
export function useLeadCalls(leadId: string) {
  const query = useQuery({
    queryKey: queryKeys.leads.calls(leadId),
    queryFn: () => fetchCalls(leadId),
    enabled: !!leadId,
  });

  return { calls: query.data, isLoading: query.isLoading, isError: query.isError };
}

/**
 * The lead's single active (in_progress) call session, or null. Derives from the SAME calls query
 * (shared cache via `select`, so it never fires a second request) — the ONE place active-call lookup
 * lives, keeping ActiveCallPanel presentation-only. If more than one call is in progress (a lead with
 * several call events), the most recent wins, since the list is newest-first.
 */
export function useActiveCall(leadId: string) {
  const query = useQuery({
    queryKey: queryKeys.leads.calls(leadId),
    queryFn: () => fetchCalls(leadId),
    enabled: !!leadId,
    select: (calls) => calls.find((call) => call.status === "in_progress") ?? null,
  });

  return { activeCall: query.data ?? null, isLoading: query.isLoading };
}

/**
 * Invalidate everything a call write touches, scoped to THIS lead: its call list/history + active
 * session (calls key), its Activity Timeline (call.started / call.completed land there), and the
 * shared Planner cache (a call creates or completes a planner event, so the Board must reflect it).
 */
function useInvalidateCalls(leadId: string) {
  const queryClient = useQueryClient();
  return () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.leads.calls(leadId) });
    queryClient.invalidateQueries({ queryKey: queryKeys.leads.activities(leadId) });
    queryClient.invalidateQueries({ queryKey: queryKeys.planner.list() });
  };
}

/** Start an ad-hoc call for a lead (Planner-first). POST /leads/{lead}/calls → the in-progress Call. */
export function useStartCall(leadId: string) {
  const invalidate = useInvalidateCalls(leadId);
  return useMutation({
    // The timer + panel state visibly switch to "in progress" on success — that is the feedback, so
    // no success toast here (only the failure path needs a message).
    mutationFn: () => apiClient.post(`/leads/${leadId}/calls`).then((r) => r.data.data as Call),
    onSuccess: () => invalidate(),
    onError: (err) => toast.error(getApiErrorMessage(err, "Couldn't start the call")),
  });
}

/** Conclude a call with a disposition. PATCH /calls/{id}/complete { outcome, notes }. */
export function useCompleteCall(leadId: string) {
  const invalidate = useInvalidateCalls(leadId);
  return useMutation({
    mutationFn: ({ callId, outcome, notes }: { callId: string; outcome: CallOutcome; notes: string | null }) =>
      apiClient.patch(`/calls/${callId}/complete`, { outcome, notes }).then((r) => r.data.data as Call),
    onSuccess: () => {
      invalidate();
      toast.success("Call logged");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Couldn't log the call")),
  });
}

/** Discard an in-progress call — tracked as cancelled, never deleted. PATCH /calls/{id}/discard. */
export function useDiscardCall(leadId: string) {
  const invalidate = useInvalidateCalls(leadId);
  return useMutation({
    mutationFn: (callId: string) => apiClient.patch(`/calls/${callId}/discard`).then((r) => r.data.data as Call),
    onSuccess: () => {
      invalidate();
      toast.success("Call discarded");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Couldn't discard the call")),
  });
}
