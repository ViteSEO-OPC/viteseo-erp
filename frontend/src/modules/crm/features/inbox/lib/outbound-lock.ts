/**
 * Phase 3H.5B — the pure rules behind the reply lock, the resolution outcome and the delivery label.
 *
 * NO RUNTIME IMPORTS, ON PURPOSE. Everything here is plain data in, plain data out, and the only
 * imports are type-only and relative, so the rules can be exercised by a throwaway node harness
 * without React, axios or the `@/` alias. Keep it that way: an enum, a namespace or a value import
 * would break that.
 */

import type {
    InboxMessage,
    InboxUnresolvedOutbound,
    ResolutionOutcome,
    ResolutionResponse,
} from "../types/inbox-types";
import type { ReplyFailure } from "../hooks/use-send-reply";

/**
 * THE TWO CODES THAT MUST NEVER OFFER A RETRY.
 *
 * Both mean the message may already have reached the customer: the server leaves such a row
 * `pending` rather than `failed` precisely so nothing re-sends it, and a Retry button in the UI
 * would undo that guarantee from the other end. Re-exported from inbox-types for existing callers.
 */
export const UNRESOLVED_FAILURE_CODES: readonly string[] = ["delivery_unknown", "provider_unavailable"];

/** The failure code the server writes when an operator confirms a message did NOT go out. */
export const OPERATOR_CONFIRMED_NOT_SENT = "operator_confirmed_not_sent";

type LockMessage = Pick<InboxMessage, "id" | "direction" | "deliveryStatus" | "failureCode">;

/**
 * Why Send is refused right now, in priority order. Null means it is not locked by any of these
 * (the capability hint, an empty draft and the length limit are checked separately).
 *
 *  - `sending`           — this browser has a reply in flight.
 *  - `unresolved`        — the SERVER lists an outbound message nobody could confirm. Survives reload.
 *  - `pending_elsewhere` — an outbound row is still `pending` and not (yet) in the unresolved list:
 *                          another operator's reply, or our own, inside its sending window.
 *  - `ambiguous`         — this browser lost the answer to its own send (no response, a 5xx, a proxy
 *                          page, or a `delivery_unknown` / `provider_unavailable` refusal).
 */
export type ComposerLockReason = "sending" | "unresolved" | "pending_elsewhere" | "ambiguous";

export type ComposerLock = {
    reason: ComposerLockReason | null;
    /** Ids from the server's unresolved list, for the thread labels and the resolve panel. */
    unresolvedIds: ReadonlySet<string>;
    /**
     * Whether the operator may clear a LOCAL ambiguous lock by hand. Only once the conversation has
     * been read back from the server AFTER the ambiguous moment, so the decision is made looking at
     * the server's view of the thread rather than at a stale one.
     */
    ambiguousDismissible: boolean;
};

export function composerLock(input: {
    unresolvedOutbound: readonly InboxUnresolvedOutbound[] | undefined;
    messages: readonly LockMessage[];
    sending: boolean;
    /** When this browser recorded an ambiguous send, or null. */
    ambiguousAt: number | null;
    /** `dataUpdatedAt` of the conversation detail the props came from. */
    serverReadAt: number;
}): ComposerLock {
    const unresolvedIds = new Set((input.unresolvedOutbound ?? []).map((entry) => entry.id));

    const pendingElsewhere = input.messages.some(
        (message) =>
            message.direction === "outbound"
            && message.deliveryStatus === "pending"
            && ! unresolvedIds.has(message.id),
    );

    const ambiguous = input.ambiguousAt !== null;

    const reason: ComposerLockReason | null = input.sending
        ? "sending"
        : unresolvedIds.size > 0
          ? "unresolved"
          : pendingElsewhere
            ? "pending_elsewhere"
            : ambiguous
              ? "ambiguous"
              : null;

    return {
        reason,
        unresolvedIds,
        ambiguousDismissible: ambiguous && input.ambiguousAt !== null && input.serverReadAt > input.ambiguousAt,
    };
}

/**
 * The id for a NEW send attempt. Always fresh.
 *
 * NEVER REUSE AN OLD ONE. The reply endpoint's idempotency key ignores the body, and a `failed` row
 * is re-claimable, so replaying an id after "Mark as not sent" would send again under the OLD row —
 * possibly with different text than the operator now sees. One id, one attempt, one message.
 */
export function nextClientRequestId(): string {
    return globalThis.crypto.randomUUID();
}

/**
 * Whether a failed send left us NOT KNOWING if the customer got it. Those lock the composer.
 *
 * `responseUnknown` is ambiguous by definition. A contract `refused` is ambiguous when it carries one
 * of the two unresolved codes (the 502 path), or returns a persisted outbound row still `pending`.
 * `retryAllowed` is deliberately NOT the signal: it is false for definitive refusals too.
 */
export function isAmbiguousReplyFailure(failure: ReplyFailure): boolean {
    if (failure.kind === "responseUnknown") {
        return true;
    }

    if (failure.kind !== "refused") {
        return false;
    }

    const { failureCode, message } = failure.reply;

    if (failureCode !== null && UNRESOLVED_FAILURE_CODES.includes(failureCode)) {
        return true;
    }

    return message !== null && message.direction === "outbound" && message.deliveryStatus === "pending";
}

/**
 * The outcome of one resolution POST, in the shapes the dialog acts on.
 *
 *  - `resolved`   — 200 with the contract and `resolved: true`. The verdict was recorded.
 *  - `conflict`   — 409 with the contract and `resolved: false`. Already settled; `reason` is the
 *                   server's own sentence.
 *  - `definitive` — 403/404/422/429 with a JSON body. Nothing was recorded.
 *  - `unknown`    — anything else: no response, 5xx, HTML, a 2xx without the contract. The verdict
 *                   may or may not have been recorded. Re-read; never re-post automatically.
 */
export type ResolveResult =
    | { kind: "resolved"; outcome: ResolutionOutcome }
    | { kind: "conflict"; reason: string }
    | { kind: "definitive"; status: 403 | 404 | 422 | 429; message: string }
    | { kind: "unknown" };

const DEFAULT_CONFLICT = "This message is no longer waiting for confirmation.";

const DEFINITIVE_MESSAGES: Record<403 | 404 | 422 | 429, string> = {
    403: "You don't have permission to resolve messages in this conversation.",
    404: "This message or conversation is no longer available.",
    422: "The request was not accepted.",
    429: "Too many attempts. Wait a minute, then try again.",
};

function jsonObject(value: unknown): Record<string, unknown> | null {
    return typeof value === "object" && value !== null && ! Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
}

/** The resolution contract body, if `value` is one. */
export function resolutionBody(value: unknown): ResolutionResponse | null {
    const data = jsonObject(value);

    if (data === null || typeof data.resolved !== "boolean") {
        return null;
    }

    return {
        resolved: data.resolved,
        outcome: data.outcome === "sent" || data.outcome === "not_sent" ? data.outcome : null,
        reason: typeof data.reason === "string" ? data.reason : null,
        message: (jsonObject(data.message) as InboxMessage | null) ?? null,
    };
}

/** Classify an answer by status and body. `status` null = no response at all. */
export function classifyResolveResponse(status: number | null, data: unknown): ResolveResult {
    if (status === null) {
        return { kind: "unknown" };
    }

    const body = resolutionBody(data);

    if (status >= 200 && status < 300) {
        return body !== null && body.resolved && body.outcome !== null
            ? { kind: "resolved", outcome: body.outcome }
            : { kind: "unknown" };
    }

    if (status === 409) {
        return body !== null && ! body.resolved
            ? { kind: "conflict", reason: body.reason ?? DEFAULT_CONFLICT }
            : { kind: "unknown" };
    }

    if ((status === 403 || status === 404 || status === 422 || status === 429) && jsonObject(data) !== null) {
        return { kind: "definitive", status, message: DEFINITIVE_MESSAGES[status] };
    }

    return { kind: "unknown" };
}

/** Classify a thrown POST. Duck-typed on axios' error shape so this file needs no axios import. */
export function classifyResolveError(error: unknown): ResolveResult {
    const candidate = jsonObject(error);

    if (candidate === null || candidate.isAxiosError !== true) {
        return { kind: "unknown" };
    }

    const response = jsonObject(candidate.response);

    if (response === null || typeof response.status !== "number") {
        return { kind: "unknown" };
    }

    return classifyResolveResponse(response.status, response.data);
}

/**
 * What an outbound row now says, after a re-read showed it left the unresolved list.
 * `null` when the row is gone from the thread or is still pending.
 */
export function settledOutcome(message: LockMessage | undefined): ResolutionOutcome | "failed" | null {
    if (message === undefined) {
        return null;
    }

    if (message.deliveryStatus === "sent") {
        return "sent";
    }

    if (message.deliveryStatus === "failed") {
        return message.failureCode === OPERATOR_CONFIRMED_NOT_SENT ? "not_sent" : "failed";
    }

    return null;
}

/**
 * What the composer's draft becomes once the server shows a message settled. Pure, so the rule is
 * testable; the composer runs it inside a `setDraft` updater so it sees the CURRENT draft.
 *
 *  - `sent` (explicit OR read back from the server) — a draft that is still that message's text is
 *    cleared, so one click cannot deliver it twice. Compared TRIMMED on both sides: the composer
 *    posts the untrimmed draft and the backend's TrimStrings middleware trims `body` before storing
 *    it. Anything else the operator is typing — different text, or a superset — is kept.
 *  - `not_sent`, only when `explicit` — the text is offered back into an EMPTY composer, never over
 *    what the operator is typing.
 *  - anything else, or no stored text — the draft is left alone.
 */
export function draftAfterResolution(
    draft: string,
    outcome: ResolutionOutcome | null,
    body: string | null,
    explicit: boolean,
): string {
    if (body === null || body.trim() === "") {
        return draft;
    }

    if (outcome === "sent") {
        return draft.trim() === body.trim() ? "" : draft;
    }

    if (outcome === "not_sent" && explicit) {
        return draft === "" ? body : draft;
    }

    return draft;
}

export type DeliveryState =
    | "unconfirmed"
    | "not_sent_by_operator"
    | "failed"
    | "sent_by_operator"
    | "sent"
    | "pending";

/**
 * The delivery state of one OUTBOUND row. Null for inbound, and for an outbound row with no status
 * (it predates delivery tracking; say nothing rather than guess).
 *
 * The server's unresolved list wins, so a stranded row (pending, no code) reads "unconfirmed" rather
 * than "pending". `providerMessageIdPresent === false` on a `sent` row is how an OPERATOR-confirmed
 * send is told apart from a provider-acknowledged one — compared strictly, so a response that lacks
 * the field is never mislabelled.
 */
export function deliveryState(
    message: LockMessage & Pick<InboxMessage, "providerMessageIdPresent">,
    unresolvedIds: ReadonlySet<string>,
): DeliveryState | null {
    if (message.direction !== "outbound") {
        return null;
    }

    if (unresolvedIds.has(message.id)) {
        return "unconfirmed";
    }

    if (message.deliveryStatus === "failed") {
        return message.failureCode === OPERATOR_CONFIRMED_NOT_SENT ? "not_sent_by_operator" : "failed";
    }

    if (message.deliveryStatus === "sent") {
        return message.providerMessageIdPresent === false ? "sent_by_operator" : "sent";
    }

    if (message.deliveryStatus === "pending") {
        return message.failureCode !== null && UNRESOLVED_FAILURE_CODES.includes(message.failureCode)
            ? "unconfirmed"
            : "pending";
    }

    return null;
}
