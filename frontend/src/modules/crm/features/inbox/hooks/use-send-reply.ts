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

/**
 * What went wrong, in the only three shapes that matter.
 *
 * THE DISTINCTION THAT THIS TYPE EXISTS FOR. Two of these mean the server definitely did NOT send
 * the message; the third means nobody knows. Collapsing them — which an earlier version of this file
 * did, by showing "please try again" for anything without a reply body — invites the operator to
 * click Send again after a dropped connection, minting a SECOND client request id for a message the
 * backend may already have delivered. The server's idempotency cannot help there: two ids are two
 * sends by definition.
 *
 *  - `refused`         the endpoint answered with its own contract. Authoritative.
 *  - `rejected`        an HTTP response arrived but carried no reply body: 401, a 403 from the
 *                      permission middleware, a 422 validation error, an HTML error page. The
 *                      request reached the server and was turned away BEFORE any send, so nothing
 *                      was delivered and saying so is safe.
 *  - `responseUnknown` no HTTP response at all: connection dropped, timed out, was reset. The
 *                      request may have arrived and been fully processed. We do not know, and the
 *                      UI must not pretend otherwise.
 */
export type ReplyFailure =
    | { kind: "refused"; reply: ReplyResponse }
    | { kind: "rejected"; status: number; message: string }
    | { kind: "responseUnknown" };

/** The reply contract body, if this error carries one. */
function replyBody(error: unknown): ReplyResponse | null {
    if (! isAxiosError(error)) {
        return null;
    }

    const data = error.response?.data as Partial<ReplyResponse> | undefined;

    // `accepted` is the discriminator: a genuine reply refusal always carries it.
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

/**
 * Classify a thrown send.
 *
 * THE TEST IS THE PRESENCE OF `error.response`, NOT THE ERROR CODE. A timeout, `ERR_NETWORK` and
 * `ECONNRESET` all describe what the BROWSER observed, and none of them says whether the server
 * received the request — so none may be read as "not sent". Only an actual HTTP response proves the
 * request arrived, and only then is it safe to tell the operator that nothing was delivered.
 */
export function classifyReplyError(error: unknown): ReplyFailure {
    const reply = replyBody(error);

    if (reply !== null) {
        return { kind: "refused", reply };
    }

    if (isAxiosError(error) && error.response !== undefined) {
        const status = error.response.status;

        return {
            kind: "rejected",
            status,
            message:
                status === 401
                    ? "Your session has ended. Please sign in again."
                    : status === 403
                      ? "You are not allowed to reply to this conversation."
                      : status === 422
                        ? "The reply could not be sent because the request was rejected."
                        : "The reply was not sent because the request was rejected.",
        };
    }

    return { kind: "responseUnknown" };
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
            if (conversationId !== null && classifyReplyError(error).kind === "refused") {
                queryClient.invalidateQueries({ queryKey: queryKeys.conversations.detail(conversationId) });
            }
        },
    });
}
