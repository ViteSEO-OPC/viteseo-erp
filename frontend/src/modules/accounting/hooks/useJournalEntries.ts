import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type { JournalEntry } from "@/modules/accounting/types";

export const journalKeys = {
  all: ["accounting", "journal-entries"] as const,
};

/** Journal entries for the current org (GET /accounting/journal-entries). */
export function useJournalEntries() {
  return useQuery({
    queryKey: journalKeys.all,
    queryFn: async (): Promise<JournalEntry[]> => {
      const { data } = await apiClient.get("/accounting/journal-entries");
      return data.data as JournalEntry[];
    },
  });
}

export interface NewJournalLine {
  account_id: number;
  debit?: number;
  credit?: number;
  memo?: string;
}

export interface NewJournalEntry {
  entry_date: string;
  memo?: string;
  reference?: string;
  post?: boolean;
  lines: NewJournalLine[];
}

/** Create a journal entry (optionally posting it), then refresh dependent views. */
export function useCreateJournalEntry() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (payload: NewJournalEntry): Promise<JournalEntry> => {
      const { data } = await apiClient.post("/accounting/journal-entries", payload);
      return data.data as JournalEntry;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: journalKeys.all });
      qc.invalidateQueries({ queryKey: ["accounting", "trial-balance"] });
      qc.invalidateQueries({ queryKey: ["accounting", "budgets"] });
    },
  });
}

/** Reverse a posted entry (posts a mirror entry). Refreshes all accounting views. */
export function useReverseEntry() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number): Promise<JournalEntry> => {
      const { data } = await apiClient.post(`/accounting/journal-entries/${id}/reverse`);
      return data.data as JournalEntry;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["accounting"] }),
  });
}
