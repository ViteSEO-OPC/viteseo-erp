"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getRequisitions,
  createRequisition,
  updateRequisition,
  deleteRequisition,
  getCandidates,
  createCandidate,
  moveCandidate,
  hireCandidate,
  sendCandidateEmail,
  getCandidateEmailDraft,
  getCandidateEmails,
  rejectCandidate,
  type CandidateEmailInput,
  type RequisitionInput,
} from "@/modules/hr/api/recruitment";
import type { Candidate } from "@/modules/hr/types/recruitment";

const REQS_KEY = ["hr", "requisitions"] as const;

export function useRequisitions() {
  return useQuery({ queryKey: REQS_KEY, queryFn: getRequisitions });
}

export function useCandidates(requisitionId: number | undefined) {
  return useQuery({
    queryKey: ["hr", "candidates", requisitionId ?? "all"],
    queryFn: () => getCandidates(requisitionId),
  });
}

export function useCreateRequisition() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createRequisition,
    onSuccess: () => qc.invalidateQueries({ queryKey: REQS_KEY }),
  });
}

export function useUpdateRequisition() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: RequisitionInput }) =>
      updateRequisition(id, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: REQS_KEY }),
  });
}

export function useDeleteRequisition() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteRequisition(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: REQS_KEY }),
  });
}

export function useCreateCandidate() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createCandidate,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["hr", "candidates"] });
      qc.invalidateQueries({ queryKey: REQS_KEY });
    },
  });
}

export function useMoveCandidate() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, stage }: { id: number; stage: string }) => moveCandidate(id, stage),
    // Optimistic: flip the card's stage in the cache SYNCHRONOUSLY (no await), so
    // it re-renders in the new column in the very same tick the drag ends — no
    // frame where it flashes back in the old column. Roll back if the API fails.
    onMutate: ({ id, stage }) => {
      qc.cancelQueries({ queryKey: ["hr", "candidates"] }); // fire-and-forget
      const prev = qc.getQueriesData<Candidate[]>({ queryKey: ["hr", "candidates"] });
      qc.setQueriesData<Candidate[]>({ queryKey: ["hr", "candidates"] }, (old) =>
        old?.map((c) => (c.id === id ? { ...c, stage } : c)),
      );
      return { prev };
    },
    onError: (_e, _v, ctx) => {
      ctx?.prev?.forEach(([key, data]) => qc.setQueryData(key, data));
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ["hr", "candidates"] }),
  });
}

/**
 * The compose modal's starting content. Only fetched while the modal is open — a draft
 * is a snapshot of the last send, so caching it past the dialog would hand the next open
 * something stale.
 */
export function useCandidateEmailDraft(
  id: number | undefined,
  type: string | undefined,
) {
  return useQuery({
    queryKey: ["hr", "candidate-email-draft", id, type],
    queryFn: () => getCandidateEmailDraft(id!, type!),
    enabled: id !== undefined && type !== undefined,
    gcTime: 0,
    refetchOnWindowFocus: false,
  });
}

export function useCandidateEmails(id: number | undefined) {
  return useQuery({
    queryKey: ["hr", "candidate-emails", id],
    queryFn: () => getCandidateEmails(id!),
    enabled: id !== undefined,
  });
}

export function useSendCandidateEmail() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...input }: { id: number } & CandidateEmailInput) =>
      sendCandidateEmail(id, input),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: ["hr", "candidates"] });
      qc.invalidateQueries({ queryKey: ["hr", "candidate-emails", id] });
      // The next open of this type should start from what was just sent.
      qc.invalidateQueries({ queryKey: ["hr", "candidate-email-draft", id] });
    },
  });
}

export function useRejectCandidate() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      reason,
      note,
    }: {
      id: number;
      reason: string;
      note: string | null;
    }) => rejectCandidate(id, { reason, note }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["hr", "candidates"] });
      qc.invalidateQueries({ queryKey: REQS_KEY });
    },
  });
}

// Hiring changes candidates, requisition openings, AND creates an employee.
export function useHireCandidate() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => hireCandidate(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["hr", "candidates"] });
      qc.invalidateQueries({ queryKey: REQS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "employees"] });
    },
  });
}
