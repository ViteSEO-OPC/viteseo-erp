"use client";

import { isAxiosError } from "axios";
import { useCallback } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type {
    InboxConversationDetail,
    LinkLeadResponse,
} from "@/modules/crm/features/inbox/types/inbox-types";

/**
 * Attach one conversation to one lead.
 *
 * THE REQUEST NAMES NOTHING BUT THE TWO IDS, AND BOTH ARE IN THE URL. The body is `{}`: the server
 * decides the tenant, and its request class rejects any attempt to name a lead, org, provider or
 * contact in the payload.
 *
 * NO RETRY, EVER — `retry: 0` stated rather than inherited, as in use-send-reply, so a future global
 * mutation default cannot quietly start re-posting.
 *
 * NO OPTIMISTIC UPDATE. The header shows the lead the SERVER says is linked, fetched back after the
 * response. A link is sticky and cannot be undone in the app, so the screen must never claim one the
 * server has not confirmed.
 *
 * WHY THIS IS SIMPLER THAN THE REPLY CLASSIFIER. A lost reply response might mean a message already
 * reached a customer, and nothing could be asked afterwards. A lost LINK response can simply be
 * checked: the conversation detail says authoritatively whether, and to what, it is now linked. So an
 * ambiguous outcome is resolved by reading the server, not by elaborate client-side bookkeeping.
 */

/** Statuses the app answers with its own JSON before or instead of linking. Each is definitive. */
const DEFINITIVE_STATUSES = [403, 404, 409, 422, 429] as const;

export type DefinitiveLinkStatus = (typeof DEFINITIVE_STATUSES)[number];

/**
 * What went wrong, in the two shapes that matter.
 *
 *  - `definitive` — the application itself answered with one of the statuses above and a JSON body.
 *                   The outcome is known; nothing was linked (409: something ELSE already was).
 *  - `unknown`    — no response, a timeout, a 5xx, or a body we cannot attribute to the app. The
 *                   request may or may not have been processed. Claim nothing; ask the server.
 *
 * 401 and 419 never get here: the shared API client turns them into a redirect to the login page.
 */
export type LinkFailure = { kind: "definitive"; status: DefinitiveLinkStatus } | { kind: "unknown" };

/** A parsed JSON object body, or null for HTML, plaintext, arrays and empty responses. */
function jsonObject(value: unknown): Record<string, unknown> | null {
    return typeof value === "object" && value !== null && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
}

/** The link endpoint's own success shape: `changed` is the discriminator. */
export function isLinkLeadResponse(value: unknown): value is LinkLeadResponse {
    const data = jsonObject(value);
    return data !== null && typeof data.changed === "boolean";
}

/**
 * Thrown when a 2xx arrives without the endpoint's contract — a proxy page, an empty body. The link
 * may well have happened, so this is classified as `unknown`, never as success.
 */
export class UnconfirmedLinkResponse extends Error {
    constructor() {
        super("The link response could not be confirmed.");
        this.name = "UnconfirmedLinkResponse";
    }
}

export function classifyLinkError(error: unknown): LinkFailure {
    if (!isAxiosError(error) || error.response === undefined) {
        return { kind: "unknown" };
    }

    const status = error.response.status;
    const definitive = (DEFINITIVE_STATUSES as readonly number[]).includes(status);

    // A matching status is only trusted with a JSON object body. The same code rendered as an HTML
    // page by a proxy or gateway is not evidence that the application decided anything.
    return definitive && jsonObject(error.response.data) !== null
        ? { kind: "definitive", status: status as DefinitiveLinkStatus }
        : { kind: "unknown" };
}

/**
 * Where a conversation actually stands, read back after an outcome we could not trust.
 *
 *  - `linked_to_selected`   — it names the lead the operator chose: the link happened.
 *  - `linked_to_other`      — it names a different lead: somebody (or ingest) got there first.
 *  - `linked_unverifiable`  — it is linked, but the lead is not visible to this viewer, so we cannot
 *                             say to which. Never reported as success.
 *  - `still_unlinked`       — nothing changed; a deliberate retry is safe.
 *
 * `leadLinked` is the authority here, never `lead !== null`: a linked conversation can carry
 * `lead: null` when the lead is archived or hidden from the viewer.
 */
export type LinkReconciliation =
    | "linked_to_selected"
    | "linked_to_other"
    | "linked_unverifiable"
    | "still_unlinked";

export function reconcileLink(
    detail: Pick<InboxConversationDetail, "leadLinked" | "lead">,
    selectedLeadId: string,
): LinkReconciliation {
    if (!detail.leadLinked) {
        return "still_unlinked";
    }

    if (detail.lead === null) {
        return "linked_unverifiable";
    }

    return detail.lead.id === selectedLeadId ? "linked_to_selected" : "linked_to_other";
}

/** The freshly re-read conversation, or `fresh: false` if the re-read itself failed. */
export type ConversationRefresh = { fresh: true; detail: InboxConversationDetail } | { fresh: false };

export function useLinkLead(conversationId: string) {
    const queryClient = useQueryClient();

    const mutation = useMutation({
        // Disabled explicitly. See the note above.
        retry: 0,

        mutationFn: async (leadId: string): Promise<LinkLeadResponse> => {
            const { data } = await apiClient.post<unknown>(
                `/conversations/${conversationId}/lead/${leadId}`,
                // Empty on purpose: both participants are in the URL.
                {},
            );

            if (!isLinkLeadResponse(data)) {
                throw new UnconfirmedLinkResponse();
            }

            return data;
        },
    });

    /**
     * Re-read the conversation (and refresh the list rows' link markers) and report whether the
     * detail really came back from the server.
     *
     * `invalidateQueries` refetches the mounted detail query and resolves when that fetch settles —
     * but it resolves the same way when the fetch FAILS, leaving the old data in the cache. Comparing
     * `dataUpdatedAt` is what tells a fresh answer from the stale one, so a failed re-read is never
     * mistaken for "still unlinked".
     */
    const refreshConversation = useCallback(async (): Promise<ConversationRefresh> => {
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
