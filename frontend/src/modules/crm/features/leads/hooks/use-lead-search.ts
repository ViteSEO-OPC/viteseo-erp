"use client";

import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { Lead } from "@/modules/crm/features/leads/types/lead-types";

/** Enough rows to be useful in a dropdown without turning it into a second Leads table. */
const RESULT_LIMIT = 6;

/** Below this, results are too noisy to be worth a request. */
const MIN_TERM_LENGTH = 2;

const DEBOUNCE_MS = 250;

/**
 * Typeahead results for the header search panel.
 *
 * Deliberately NOT a new search system: it is another consumer of the SAME `GET /leads?search=`
 * endpoint the Leads list already uses, so server-side authorization is identical — the index is
 * policy-scoped, and a BD receives only leads visible to them (`Lead::isVisibleTo`). Nothing is
 * filtered client-side, so the browser never holds records the API would not have returned anyway.
 *
 * Separate from `useLeads()` because that hook is URL-driven (it reads `useSearchParams` and writes
 * `?search=`), which only makes sense on the Leads route. The header lives on every route, so it
 * needs a term passed in rather than read from the address bar.
 *
 * @param term    the raw input value; debounced here so typing doesn't fire a request per keystroke
 * @param enabled false collapses this to zero requests — the panel is closed and nothing is shown
 */
export function useLeadSearch(term: string, enabled: boolean) {
  const [debounced, setDebounced] = useState("");

  useEffect(() => {
    if (!enabled) return;
    const timer = setTimeout(() => setDebounced(term.trim()), DEBOUNCE_MS);

    return () => clearTimeout(timer);
  }, [term, enabled]);

  // Derived rather than reset through the effect above: when the panel closes there is nothing to
  // synchronise, and writing state from an effect body just to clear it costs an extra render pass.
  const searchTerm = enabled ? debounced : "";
  const active = searchTerm.length >= MIN_TERM_LENGTH;

  const query = useQuery<{ data: Lead[] }>({
    // Reuses the existing leads list key factory, scoped so header results can never collide with
    // (or evict) the Leads table's own cached pages.
    queryKey: queryKeys.leads.list({ scope: "header-search", search: searchTerm, perPage: RESULT_LIMIT }),
    queryFn: () =>
      apiClient
        .get("/leads", { params: { page: 1, per_page: RESULT_LIMIT, search: searchTerm } })
        .then((r) => r.data),
    enabled: active,
  });

  return {
    results: query.data?.data ?? [],
    // Only "loading" once a request is actually in flight, so the panel shows nothing (not a
    // spinner) while the user is still below the minimum term length.
    isLoading: active && query.isPending,
    isError: active && query.isError,
    /** Whether a search is currently being run — drives whether the results area renders at all. */
    active,
  };
}
