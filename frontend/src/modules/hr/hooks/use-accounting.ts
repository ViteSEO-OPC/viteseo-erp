"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getGlAccounts,
  createGlAccount,
  updateGlAccount,
  deleteGlAccount,
  getRunJournal,
  buildRunJournal,
  postJournalEntry,
  reverseJournalEntry,
} from "@/modules/hr/api/accounting";
import type { GlAccountInput } from "@/modules/hr/types/accounting";

const CHART_KEY = ["hr", "gl-accounts"] as const;
const JOURNAL_KEY = ["hr", "run-journal"] as const;

export function useGlAccounts() {
  return useQuery({ queryKey: CHART_KEY, queryFn: getGlAccounts });
}

export function useCreateGlAccount() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: GlAccountInput) => createGlAccount(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: CHART_KEY }),
  });
}

export function useUpdateGlAccount() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: Partial<GlAccountInput> }) =>
      updateGlAccount(id, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: CHART_KEY }),
  });
}

export function useDeleteGlAccount() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteGlAccount(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: CHART_KEY }),
  });
}

/** What is on the books for a run. `enabled` so the dialog only fetches when it opens. */
export function useRunJournal(runId: number | null) {
  return useQuery({
    queryKey: [...JOURNAL_KEY, runId] as const,
    queryFn: () => getRunJournal(runId!),
    enabled: runId !== null,
  });
}

/**
 * The three journal actions. Each invalidates the run's journal AND the chart, because a build can
 * fail on an unmapped role and the user's next move is to go and map it.
 */
export function useBuildRunJournal() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ runId, postingDate }: { runId: number; postingDate?: string }) =>
      buildRunJournal(runId, postingDate),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: JOURNAL_KEY });
      qc.invalidateQueries({ queryKey: CHART_KEY });
    },
  });
}

export function usePostJournalEntry() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => postJournalEntry(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: JOURNAL_KEY }),
  });
}

export function useReverseJournalEntry() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => reverseJournalEntry(id, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: JOURNAL_KEY }),
  });
}
