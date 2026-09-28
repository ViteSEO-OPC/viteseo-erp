"use client";

import { useQuery } from "@tanstack/react-query";

import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { LeadPickerPage } from "@/modules/crm/features/inbox/types/inbox-types";

/**
 * Candidate leads for the "Link lead" picker.
 *
 * `scope=linkable` IS THE WHOLE POINT. The ordinary Leads list only returns qualified CRM leads,
 * which would hide every unqualified prospect — the majority of live leads, and the most likely
 * match for an enquiry from an unknown business. Linkable mode returns every lead this operator
 * could actually link, prospects included, in a compact five-field shape.
 *
 * SEARCH-FIRST. Nothing is fetched until the operator has typed something: with over a thousand
 * leads, an unfiltered first page is noise, and the caller passes an already-debounced term so a
 * request fires per pause rather than per keystroke.
 *
 * The page size is the server's (ten); this hook sends only `page` and never a `per_page` the
 * endpoint would ignore.
 */
export function useLinkableLeads(search: string, page: number, enabled: boolean) {
    const term = search.trim();

    return useQuery({
        queryKey: queryKeys.leads.linkable({ search: term, page }),
        enabled: enabled && term !== "",
        queryFn: async (): Promise<LeadPickerPage> => {
            const { data } = await apiClient.get<LeadPickerPage>("/leads", {
                params: { scope: "linkable", search: term, page },
            });
            return data;
        },
        // A search result is a snapshot for a decision made seconds later; refetching it on window
        // focus would reshuffle the rows under the operator's cursor.
        refetchOnWindowFocus: false,
    });
}
