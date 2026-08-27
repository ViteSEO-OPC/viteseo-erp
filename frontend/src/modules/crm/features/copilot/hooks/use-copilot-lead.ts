"use client";

import { useCallback } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { Lead } from "@/modules/crm/features/leads/types/lead-types";
import type { CopilotLeadMatch } from "@/modules/crm/features/copilot/engine/types";

/** Below this a "search" is really a stray word; asking the API would only return noise. */
const MIN_TERM_LENGTH = 2;

/**
 * Resolve a lead the user named in conversation.
 *
 * DELIBERATELY NOT A NEW SEARCH SYSTEM, for the same reason `useLeadSearch` isn't: this is another
 * consumer of the SAME `GET /leads?search=` endpoint the Leads list and the header typeahead already
 * use. Server-side authorization is therefore identical — the index is policy-scoped
 * (`Lead::isVisibleTo`), so a BD receives only their own leads and nothing is filtered client-side.
 * A BD asking about another BD's lead gets an empty result, which reads as "I couldn't find it" and
 * never confirms that the lead exists.
 *
 * IMPERATIVE, NOT A `useQuery`. A lookup happens once per message, for a term known only at send
 * time — a hook cannot be called conditionally mid-turn. `fetchQuery` runs the same request through
 * the same client and populates the same cache, so a repeat question about the same company is
 * served from memory. Keeping it out of render also lets the response engine stay synchronous and
 * pure: the turn awaits this first, then builds the reply from a resolved value.
 *
 * The Copilot's results are cached under their own `scope` so they can never collide with (or evict)
 * the Leads table's pages or the header panel's.
 *
 * NO PAGE SIZE IS REQUESTED. `LeadController::index` paginates at a fixed 10 and reads no page-size
 * parameter, so sending one would be a knob the API ignores — and a cache key claiming a size it
 * never applied. Ten candidates is already far more than a chat reply needs: one match answers the
 * question, and several only need to prove the term was ambiguous.
 */
export function useCopilotLeadLookup() {
  const queryClient = useQueryClient();

  return useCallback(
    async (term: string): Promise<CopilotLeadMatch[]> => {
      const search = term.trim();
      if (search.length < MIN_TERM_LENGTH) return [];

      try {
        const rows = await queryClient.fetchQuery({
          queryKey: queryKeys.leads.list({ scope: "copilot-lookup", search }),
          queryFn: () =>
            apiClient
              .get("/leads", { params: { page: 1, search } })
              .then((r) => (r.data.data ?? []) as Lead[]),
        });

        return rows.map(toMatch);
      } catch {
        // A failed lookup must not break the turn. An empty result renders the same honest
        // "I couldn't find that" the not-found path uses, rather than an error bubble.
        return [];
      }
    },
    [queryClient],
  );
}

function toMatch(lead: Lead): CopilotLeadMatch {
  return {
    id: lead.id,
    name: lead.business,
    // `updateStatus` is the Lead Status field on the frontend model (pipeline stage).
    status: lead.updateStatus,
    priority: lead.priority,
    websiteStatus: lead.websiteStatus ?? null,
    assignedToName: lead.assignedTo?.name ?? null,
    score: typeof lead.score === "number" ? lead.score : null,
  };
}
