"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { searchProjects } from "@/modules/projects/api/search";
import { projectKeys } from "@/modules/projects/lib/query-keys";
import { SEARCH_MIN_LENGTH } from "@/modules/projects/types/search";

/**
 * Global search across projects and tasks for the topbar menu.
 *
 * Pass an ALREADY-DEBOUNCED term — the query key is the term, so every distinct
 * value is one request. Terms shorter than SEARCH_MIN_LENGTH are never sent
 * (the API 422s on those), and the previous term's results stay on screen while
 * the next one loads so the menu doesn't flash empty between keystrokes.
 */
export function useProjectSearch(term: string, limit = 5) {
  const q = term.trim();
  const enabled = q.length >= SEARCH_MIN_LENGTH;

  return useQuery({
    queryKey: projectKeys.search(q, limit),
    queryFn: ({ signal }) => searchProjects(q, limit, signal),
    enabled,
    placeholderData: keepPreviousData,
    // Results for a given term rarely change within a single search session,
    // so re-typing one (or backspacing into it) is served from cache.
    staleTime: 30_000,
    // Nothing here is worth a retry storm on a keystroke-driven endpoint.
    retry: false,
  });
}
