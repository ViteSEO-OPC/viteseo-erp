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
 * the message; the third means nobody knows. Collapsing them — which an earlier version of this
 * file did, by showing "please try again" for anything without a reply body — invites the operator
 * to click Send again after a dropped connection, minting a SECOND client request id for a message
 * the backend may already have delivered. The server's idempotency cannot help there: two ids are
 * two sends by definition.
 *
 *  - `refused`         the endpoint answered with its own contract. Authoritative.
 *  - `rejected`        a response our application provably produced BEFORE the send could run.
 *  - `responseUnknown` nobody knows. No response at all, or one we cannot attribute to the app.
 */
export type ReplyFailure =
    | { kind: "refused"; reply: ReplyResponse }
    | { kind: "rejected"; status: number; message: string }
    | { kind: "responseUnknown" };

/** Statuses our application can only emit from middleware or validation — never after a send. */
const PRE_SEND_STATUSES = [401, 403, 419, 422];

/** A parsed JSON object body, or null for HTML, plaintext, arrays and empty responses. */
function jsonObject(value: unknown): Record<string, unknown> | null {
    return typeof value === "object" && value !== null && ! Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
}

/** The reply contract body, if this error carries one. */
function replyBody(error: unknown): ReplyResponse | null {
    if (! isAxiosError(error)) {
        return null;
    }

    const data = jsonObject(error.response?.data);

    // `accepted` is the discriminator: a genuine reply refusal always carries it as a boolean.
    return data !== null && typeof data.accepted === "boolean"
        ? {
              accepted: data.accepted,
              failureCode: (data.failureCode as ReplyResponse["failureCode"]) ?? null,
              reason: typeof data.reason === "string" ? data.reason : null,
              retryAllowed: data.retryAllowed === true,
              message: (data.message as ReplyResponse["message"]) ?? null,
          }
        : null;
}

/**
 * Classify a thrown send.
 *
 * ================== WHY THE PRESENCE OF A RESPONSE PROVES NOTHING ==================
 *
 * An earlier version read `error.response !== undefined` as "the server answered, so the request
 * reached it and was turned away". That is false in exactly the case that matters. nginx, the PHP
 * upstream or Cloudflare can return 500, 502, 503 or 504 AFTER the application has received the
 * request and run it to completion — a worker that dies while writing the response, a gateway
 * timeout on a slow send that nonetheless finished. The status describes the edge, not the
 * application, so treating it as proof of non-delivery would tell the operator "not sent" about a
 * message already on its way to a customer.
 *
 * So a response is trusted only when it can be ATTRIBUTED to this application:
 *
 *   1. It carries the reply contract (a boolean `accepted`) — unambiguously our endpoint.
 *   2. It is a 422 carrying Laravel's `errors` object — validation, which runs in the FormRequest
 *      before the controller is constructed. No proxy emits that shape.
 *   3. It is a 401/403/419 carrying a JSON `message` — our auth, session and permission middleware,
 *      all of which run before the controller. Proxy refusals at these statuses are HTML.
 *
 * Everything else — any 5xx, any HTML or plaintext body, any JSON we do not recognise — is
 * UNKNOWN. The asymmetry justifies the caution: misreading a rejection as unknown costs the
 * operator a locked composer and a careful sentence, while misreading an unknown as a rejection
 * costs a real customer a duplicate message.
 */
export function classifyReplyError(error: unknown): ReplyFailure {
    const reply = replyBody(error);

    if (reply !== null) {
        return { kind: "refused", reply };
    }

    if (! isAxiosError(error) || error.response === undefined) {
        return { kind: "responseUnknown" };
    }

    const status = error.response.status;
    const data = jsonObject(error.response.data);

    if (data === null || ! PRE_SEND_STATUSES.includes(status)) {
        // Includes every 5xx and every HTML/plaintext/proxy page.
        return { kind: "responseUnknown" };
    }

    // Laravel validation: `errors` is an object keyed by field. Provably pre-controller.
    if (status === 422 && jsonObject(data.errors) !== null) {
        return {
            kind: "rejected",
            status,
            message: "The reply was not sent because the request was rejected.",
        };
    }

    // Auth, session and permission middleware, all of which precede the controller.
    if (status !== 422 && typeof data.message === "string") {
        return {
            kind: "rejected",
            status,
            message:
                status === 403
                    ? "You are not allowed to reply to this conversation."
                    : "Your session has ended. Please sign in again.",
        };
    }

    // A recognised status with an unrecognised body. Not attributable; assume nothing.
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
