"use client";

import { isAxiosError } from "axios";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { ReplyResponse } from "@/modules/crm/features/inbox/types/inbox-types";

/**
 * Send one reply in one conversation.
 *
 * THE PAYLOAD IS TWO FIELDS AND THAT IS THE WHOLE POINT. The server decides the provider, the
 * channel, the sending account, the recipient and the credential; the browser cannot name any of
 * them, and the API rejects the attempt with a 422 if it tries. So this hook posts a body and an
 * id, and nothing here knows or needs to know whether the thread is Messenger or Instagram.
 *
 * NO RETRY, EVER, AND NOT BY ACCIDENT. `retry: 0` is stated rather than inherited: react-query
 * already defaults mutations to no retry, but this particular POST must not start retrying because
 * somebody later sets a global mutation default. A resend is a second message to a real person.
 *
 * NO OPTIMISTIC UPDATE. Nothing writes a local "sent" bubble. The row the thread renders is always
 * the one the server persisted, fetched back by invalidation, because "sent" is a claim only the
 * server can make — and for the two ambiguous outcomes, not even it can.
 */

/** Pull the reply body out of either a 201 or the thrown 4xx/5xx it shares a shape with. */
export function replyResponseFrom(error: unknown): ReplyResponse | null {
    if (! isAxiosError(error)) {
        return null;
    }

    const data = error.response?.data as Partial<ReplyResponse> | undefined;

    // `accepted` is the discriminator: a genuine reply refusal always carries it. A 401, a 403 from
    // the permission middleware, or an HTML error page will not, and must not be read as one.
    return data !== undefined && typeof data.accepted === "boolean"
        ? {
              accepted: data.accepted,
              failureCode: data.failureCode ?? null,
              reason: data.reason ?? null,
              retryAllowed: data.retryAllowed ?? false,
              message: data.message ?? null,
          }
        : null;
}

export function useSendReply(conversationId: string | null) {
    const queryClient = useQueryClient();

    return useMutation({
        // Disabled explicitly. See the note above.
        retry: 0,

        mutationFn: async (variables: { body: string; clientRequestId: string }): Promise<ReplyResponse> => {
            const { data } = await apiClient.post<ReplyResponse>(
                `/conversations/${conversationId}/reply`,
                // Exactly these two keys. No messageType, provider, channel, org, account,
                // recipient, lead or token — the request class prohibits every one of them.
                { body: variables.body, clientRequestId: variables.clientRequestId },
            );

            return data;
        },

        onSuccess: () => {
            if (conversationId === null) {
                return;
            }

            /*
             * Refetch rather than patch. The detail query is the only thing that knows the real
             * delivery state, and the list needs the new latest-message preview and ordering. Both
             * are cheap here — one conversation and one page of rows — and correctness is worth more
             * than the round trip on an action a human performs a few times a minute.
             */
            queryClient.invalidateQueries({ queryKey: queryKeys.conversations.detail(conversationId) });
            queryClient.invalidateQueries({ queryKey: queryKeys.conversations.lists() });
        },

        onError: (error) => {
            /*
             * A refusal is not a broken request, so the conversation is refetched too: an
             * outside_messaging_window or not_configured answer means this client's idea of what is
             * allowed is stale, and the capability field it renders the composer from lives on the
             * detail payload. Without this the operator sees an enabled composer that will keep
             * refusing.
             *
             * Deliberately NOT invalidated for a transport-level failure with no reply body — there
             * is nothing new to learn from the server in that case.
             */
            if (conversationId !== null && replyResponseFrom(error) !== null) {
                queryClient.invalidateQueries({ queryKey: queryKeys.conversations.detail(conversationId) });
            }
        },
    });
}
