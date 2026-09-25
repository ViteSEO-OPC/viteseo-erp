/**
 * The conversation inbox API shape, as returned by GET /crm/conversations.
 *
 * Only the fields the read-only inbox renders are typed. The API deliberately never sends the raw
 * provider payload, the idempotency key or the sender's platform identifier, so there is nothing
 * here to model for them.
 */

export type InboxChannel = "messenger" | "instagram_dm";

export type InboxLeadSummary = {
    id: string;
    businessName: string | null;
    contactName: string | null;
    status?: string | null;
};

export type InboxLatestMessage = {
    direction: "inbound" | "outbound";
    messageType: string;
    preview: string | null;
    occurredAt: string | null;
    receivedAt: string | null;
};

export type InboxConversation = {
    id: string;
    provider: string;
    channel: string;
    status: string;
    startedAt: string | null;
    lastMessageAt: string | null;
    updatedAt: string | null;
    leadLinked: boolean;
    lead: InboxLeadSummary | null;
    latestMessage: InboxLatestMessage | null;
};

/**
 * Delivery state, as `crm_messages.delivery_status` records it.
 *
 * Null on inbound turns: we never delivered them, and the API does not invent a state for them.
 */
export type DeliveryStatus = "pending" | "sent" | "failed";

/**
 * Our OWN failure vocabulary — never the provider's prose.
 *
 * Widened to `string` on purpose: the server owns this list and may add to it, and an unrecognised
 * code must render as a neutral message rather than break the thread. The named members exist so
 * the cases the UI must treat differently are spellable and checkable.
 */
export type OutboundFailureCode =
    | "unauthorized"
    | "tenant_mismatch"
    | "unsupported_channel"
    | "invalid_request"
    | "resource_unmapped"
    | "resource_ambiguous"
    | "resource_unavailable"
    | "not_configured"
    | "provider_unavailable"
    | "provider_rate_limited"
    | "outside_messaging_window"
    | "policy_violation"
    | "credential_rejected"
    | "delivery_unknown"
    | (string & {});

/**
 * THE TWO CODES THAT MUST NEVER OFFER A RETRY.
 *
 * Both mean the message may already have reached the customer: the server leaves such a row
 * `pending` rather than `failed` precisely so nothing re-sends it, and a Retry button in the UI
 * would undo that guarantee from the other end.
 */
export const UNRESOLVED_FAILURE_CODES: readonly string[] = ["delivery_unknown", "provider_unavailable"];

export type InboxMessage = {
    id: string;
    direction: "inbound" | "outbound";
    messageType: string;
    body: string | null;
    hasAttachment: boolean;
    occurredAt: string | null;
    receivedAt: string | null;
    processedAt: string | null;

    /** Null for inbound. `pending` + a failureCode is the unresolved state, not a failure. */
    deliveryStatus: DeliveryStatus | null;
    failureCode: OutboundFailureCode | null;
    deliveredAt: string | null;

    /** Whether a provider message id was recorded. The identifier itself is never sent. */
    providerMessageIdPresent: boolean;
};

/**
 * Why the composer is not offered, when it is not.
 *
 * A PRESENTATION HINT ONLY. The server re-checks everything on send, so `enabled: true` is not a
 * promise that a reply will go through — the messaging window can close between render and click.
 * Widened to `string` for the same reason as the failure codes: the server owns the vocabulary.
 */
export type ReplyDisabledReason =
    | "permission_denied"
    | "unsupported_channel"
    | "outbound_disabled"
    | (string & {});

export type InboxReplyCapability = {
    enabled: boolean;
    /** Null exactly when `enabled` is true. */
    reason: ReplyDisabledReason | null;
};

export type InboxConversationDetail = {
    id: string;
    provider: string;
    channel: string;
    status: string;
    startedAt: string | null;
    lastMessageAt: string | null;
    leadLinked: boolean;
    lead: InboxLeadSummary | null;
    messageCount: number;
    messages: InboxMessage[];
    reply: InboxReplyCapability;
};

/**
 * What POST /conversations/{id}/reply answers with.
 *
 * ARRIVES TWO DIFFERENT WAYS, WHICH IS THE THING TO KNOW ABOUT THIS TYPE. `accepted: true` comes
 * back as a 201 and lands in a react-query `onSuccess`. Every refusal is a 4xx or 5xx — 409 for a
 * closed window or disabled transport, 422 for a bad request, 429 for throttling, 502 for the two
 * ambiguous outcomes — and axios throws on those, so the SAME shape arrives inside
 * `error.response.data`. Both paths are modelled here rather than split into two types, because the
 * body is identical and a caller that forgets the second path silently loses every failure reason.
 *
 * `failureCode` is the machine contract; `reason` is display copy the server owns. Never branch
 * logic on `reason`.
 */
export type ReplyResponse = {
    accepted: boolean;
    failureCode: OutboundFailureCode | null;
    reason: string | null;
    /**
     * Whether the SERVER considers a resend safe. False for both unresolved codes, because the
     * message may already have reached the customer. 3H.2 offers no Retry affordance at all, so
     * nothing reads this yet — it is modelled so 3H.5 cannot forget it exists.
     */
    retryAllowed: boolean;
    /** The persisted row, when one exists. Null for every refusal raised before persistence. */
    message: InboxMessage | null;
};

/** The channel filter offered in the UI. `all` is the absence of a filter, not a value we send. */
export type ChannelFilter = "all" | InboxChannel;

/** Display metadata for a channel. Unknown channels fall back rather than crashing the row. */
export const CHANNEL_LABELS: Record<string, string> = {
    messenger: "Messenger",
    instagram_dm: "Instagram",
    whatsapp: "WhatsApp",
    web_chat: "Web chat",
    lead_ads: "Lead Ads",
};

export function channelLabel(channel: string): string {
    return CHANNEL_LABELS[channel] ?? channel;
}
