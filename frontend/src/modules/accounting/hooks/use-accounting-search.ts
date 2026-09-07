"use client";

import { useQuery } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type { SearchGroup, SearchItem, SearchResults } from "@/core/use-search";

export type { SearchGroup, SearchItem, SearchResults };

/** Shortest term the backend will run a query for — kept in sync with SearchService::MIN_LENGTH. */
export const MIN_SEARCH_LENGTH = 2;

export const accountingSearchKeys = {
  all: ["accounting", "search"] as const,
  term: (q: string) => ["accounting", "search", q] as const,
};

/**
 * The Accounting portal's global search — invoices, bills, contacts and the
 * chart of accounts, already grouped by the backend into the platform's shared
 * `SearchGroup[]` shape so the dropdown renders identically to HR's.
 *
 * Which groups come back is decided server-side (availed features + the user's
 * permissions), so this hook never has to know about entitlements: it renders
 * whatever it is handed.
 *
 * Pass the DEBOUNCED term — the query only runs from `MIN_SEARCH_LENGTH` up, so
 * a single keystroke costs nothing.
 */
export function useAccountingSearch(q: string) {
  const term = q.trim();

  return useQuery({
    queryKey: accountingSearchKeys.term(term),
    enabled: term.length >= MIN_SEARCH_LENGTH,
    queryFn: async (): Promise<SearchResults> => {
      const { data } = await apiClient.get("/accounting/search", { params: { q: term } });
      return data.data as SearchResults;
    },
  });
}
