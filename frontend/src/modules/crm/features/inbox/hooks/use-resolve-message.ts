"use client";

import { useCallback } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { classifyResolveResponse } from "@/modules/crm/features/inbox/lib/outbound-lock";
import type {
    InboxConversationDetail,
    ResolutionOutcome,
} from "@/modules/crm/features/inbox/types/inbox-types";

/**
 * Record an operator's verdict on an outbound message whose delivery nobody could confirm.
 *
 * THE BODY IS `{ outcome }` AND NOTHING ELSE. The conversation and message are in the URL; every
 * other key (status, failure code, provider id…) is a 422 by design.
 *
 * NEVER SENDS A MESSAGE AND NEVER RETRIES. `retry: 0` is stated rather than inherited, and nothing
 * in this file re-posts: an unknown outcome is settled by READING the conversation back. A second
 * POST only ever comes from a second deliberate click, which the server's compare-and-set makes
 * harmless (it answers 409 if the first one landed).
 */

/** Thrown when a 2xx arrives without `resolved: true` — a proxy page, an empty body. Unknown, never success. */
export class UnconfirmedResolutionResponse extends Error {
    constructor() {
        super("The resolution response could not be confirmed.");
        this.name = "UnconfirmedResolutionResponse";
    }
}

/** The freshly re-read conversation, or `fresh: false` if the re-read itself failed. */
export type ResolveRefresh = { fresh: true; detail: InboxConversationDetail } | { fresh: false };

export function useResolveMessage(conversationId: string) {
    const queryClient = useQueryClient();

    const mutation = useMutation({
        // Disabled explicitly. See the note above.
        retry: 0,

        mutationFn: async (variables: { messageId: string; outcome: ResolutionOutcome }): Promise<ResolutionOutcome> => {
            const response = await apiClient.post<unknown>(
                `/conversations/${conversationId}/messages/${variables.messageId}/resolution`,
                // Exactly this one key.
                { outcome: variables.outcome },
            );

            const result = classifyResolveResponse(response.status, response.data);

            if (result.kind !== "resolved") {
                throw new UnconfirmedResolutionResponse();
            }

            return result.outcome;
        },
    });

    /**
     * Re-read the conversation (and refresh the list rows) and report whether the detail really
     * came back. `invalidateQueries` resolves the same way when the refetch FAILS, so `dataUpdatedAt`
     * is compared to tell a fresh answer from the cached one. GET only — never a POST.
     */
    const refreshConversation = useCallback(async (): Promise<ResolveRefresh> => {
        const key = queryKeys.conversations.detail(conversationId);
        const before = queryClient.getQueryState(key)?.dataUpdatedAt ?? 0;

        void queryClient.invalidateQueries({ queryKey: queryKeys.conversations.lists() });
        await queryClient.invalidateQueries({ queryKey: key });

        const state = queryClient.getQueryState<InboxConversationDetail>(key);

        return state?.data !== undefined && state.dataUpdatedAt > before
            ? { fresh: true, detail: state.data }
            : { fresh: false };
    }, [conversationId, queryClient]);

    return { mutation, refreshConversation };
}
