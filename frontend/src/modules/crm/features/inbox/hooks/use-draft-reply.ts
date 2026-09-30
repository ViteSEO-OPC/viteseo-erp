"use client";

import { isAxiosError } from "axios";
import { useMutation } from "@tanstack/react-query";

import { apiClient } from "@/modules/crm/lib/axios";
import type { DraftReplyResponse } from "@/modules/crm/features/inbox/types/inbox-types";

/**
 * Ask the server for an AI-written DRAFT reply (Phase 4A-lite).
 *
 * THIS NEVER SENDS ANYTHING, AND NEITHER DOES ANYTHING IT TRIGGERS. The endpoint returns text and
 * writes nothing; the caller puts that text in the composer and the operator decides whether to
 * press the normal Send. So there is deliberately:
 *
 *  - NO INVALIDATION. Nothing on the server changed, so there is nothing to refetch — and keeping
 *    this hook free of query-cache side effects means it cannot wake anything else up.
 *  - NO RETRY. Each call is a paid model request with its own throttle; `retry: 0` is stated so a
 *    later global mutation default cannot quietly multiply it.
 *  - AN EMPTY BODY. The request class refuses any field (422); the server derives everything from
 *    the conversation id and the authenticated user.
 */
export function useDraftReply(conversationId: string) {
    return useMutation({
        retry: 0,

        mutationFn: async (): Promise<DraftReplyResponse> => {
            const { data } = await apiClient.post<DraftReplyResponse>(`/conversations/${conversationId}/draft`, {});

            return data;
        },
    });
}

/** Maximum draft length the composer will accept, mirrored from the reply endpoint's ceiling. */
export const DRAFT_MAX_LENGTH = 2000;

/**
 * The draft text from a 2xx body, truncated to the reply ceiling — or null when the body is not the
 * contract (a proxy's HTML 200, an empty completion, a missing field).
 */
export function draftTextFrom(data: unknown): string | null {
    if (typeof data !== "object" || data === null || Array.isArray(data)) {
        return null;
    }

    const draft = (data as Record<string, unknown>).draft;

    if (typeof draft !== "string" || draft.trim() === "") {
        return null;
    }

    return draft.slice(0, DRAFT_MAX_LENGTH);
}

const GENERIC_DRAFT_ERROR = "The AI draft could not be generated. Try again.";

/**
 * What to tell the operator when a draft request failed.
 *
 * A contract refusal carries the server's own `failureCode` + `message`, and the message is shown.
 * The route's OWN throttle is also a 429 but has no `failureCode` — that one gets its own sentence.
 * Anything else (403, 404, 422, no response, an HTML page) gets one neutral line: nothing was sent,
 * so unlike a lost reply there is nothing ambiguous to lock over.
 */
export function draftErrorMessage(error: unknown): string {
    if (! isAxiosError(error) || error.response === undefined) {
        return GENERIC_DRAFT_ERROR;
    }

    const data = error.response.data;
    const body = typeof data === "object" && data !== null && ! Array.isArray(data)
        ? (data as Record<string, unknown>)
        : null;

    if (body !== null && typeof body.failureCode === "string" && typeof body.message === "string" && body.message !== "") {
        return body.message;
    }

    if (error.response.status === 429) {
        return "Too many draft requests. Wait a minute.";
    }

    return GENERIC_DRAFT_ERROR;
}
