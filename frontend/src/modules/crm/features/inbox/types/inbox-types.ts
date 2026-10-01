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
    | "operator_confirmed_not_sent"
    | (string & {});

/**
 * THE TWO CODES THAT MUST NEVER OFFER A RETRY. Defined in `lib/outbound-lock` (which must stay free of
 * runtime imports) and re-exported here so existing callers keep their import.
 */
export { UNRESOLVED_FAILURE_CODES } from "@/modules/crm/features/inbox/lib/outbound-lock";

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

    /**
     * Whether a provider message id was recorded. The identifier itself is never sent. On an
     * outbound `sent` row, `false` means an OPERATOR confirmed the send (Phase 3H.5A never invents
     * a provider id), `true` means the provider acknowledged it.
     */
    providerMessageIdPresent: boolean;
};

/**
 * One outbound message whose delivery nobody could confirm (Phase 3H.5A), as listed on the detail
 * payload. Read from the database, so it survives a reload, another device and another operator.
 * Identifiers and timing only — the text, if shown, comes from `messages`.
 */
export type InboxUnresolvedOutbound = {
    id: string;
    createdAt: string | null;
    sendAttemptedAt: string | null;
    failureCode: OutboundFailureCode | null;
    /** True when no outcome was ever recorded (the request died mid-send). */
    stranded: boolean;
};

/** An operator's verdict on an unresolved outbound message. */
export type ResolutionOutcome = "sent" | "not_sent";

/**
 * What POST /conversations/{id}/messages/{message}/resolution answers with. 200 carries
 * `resolved: true`; a 409 carries the same shape with `resolved: false`, `outcome: null` and the
 * server's own `reason`. `message` is the row as it now stands.
 */
export type ResolutionResponse = {
    resolved: boolean;
    outcome: ResolutionOutcome | null;
    reason: string | null;
    message: InboxMessage | null;
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
    link: InboxLinkCapability;
    /** Optional so a server without Phase 3H.5A still parses; always read as `?? []`. */
    unresolvedOutbound?: InboxUnresolvedOutbound[];
    /** Phase 4A-lite. Optional so an older server still parses; absent reads as "not offered". */
    draft?: InboxDraftCapability;
};

/**
 * Whether to offer "Draft with AI" — a PRESENTATION HINT computed by the server. The draft endpoint
 * re-checks permission, the feature flag and the key, so a stale `enabled: true` ends in a refusal.
 */
export type DraftDisabledReason = "permission_denied" | "ai_disabled" | "not_configured" | (string & {});

export type InboxDraftCapability = {
    enabled: boolean;
    /** Null exactly when `enabled` is true. */
    reason: DraftDisabledReason | null;
};

/**
 * POST /conversations/{id}/draft on a 200. Text for the composer ONLY — the endpoint never sends,
 * never writes a message row, and this client never forwards it to the reply endpoint by itself.
 */
export type DraftReplyResponse = {
    draft: string;
    failureCode: null;
    model: string;
    generatedAt: string;
};

/** The endpoint's closed failure vocabulary, widened to `string` because the server owns it. */
export type DraftReplyFailureCode =
    | "disabled"
    | "not_configured"
    | "empty_conversation"
    | "timeout"
    | "rate_limited"
    | "auth_failed"
    | "upstream_error"
    | "invalid_response"
    | (string & {});

/**
 * A contract refusal from the draft endpoint (409 / 429 / 502), arriving inside the axios error.
 * `message` is the server's provider-neutral copy; branch logic on `failureCode`, never on `message`.
 */
export type DraftReplyFailure = {
    draft: null;
    failureCode: DraftReplyFailureCode;
    message: string;
};

/**
 * Why the "Link lead" control is not offered, when it is not.
 *
 * Widened to `string` for the same reason as the reply reasons: the server owns this vocabulary,
 * and a reason this build does not know must render as "no action" rather than break the header.
 */
export type LinkDisabledReason = "permission_denied" | "already_linked" | (string & {});

/**
 * Whether this viewer may attach this conversation to a lead — computed by the SERVER.
 *
 * THE BUTTON IS DECIDED HERE AND NOWHERE ELSE. A browser-side permission check would read the auth
 * payload's permission list, which cannot see the backend's super_admin bypass, and would hide the
 * control from exactly the people allowed to use it. Like `reply`, this is a hint: the endpoint
 * re-checks everything, so a stale `enabled: true` ends in a 403 or 409, never in a wrong link.
 */
export type InboxLinkCapability = {
    enabled: boolean;
    /** Null exactly when `enabled` is true. */
    reason: LinkDisabledReason | null;
};

/**
 * One row of GET /crm/leads?scope=linkable — the picker's own compact resource, NOT the Leads
 * table's LeadResource. These five fields are all the endpoint returns; there is no email, phone or
 * address to show, by design.
 */
export type LeadPickerCandidate = {
    id: string;
    businessName: string | null;
    contactName: string | null;
    status: string;
    /** A discovered business not yet qualified into the CRM pipeline. Both kinds are linkable. */
    isProspect: boolean;
};

/** The paginated envelope around picker rows. Ten per page, set by the server. */
export type LeadPickerPage = {
    data: LeadPickerCandidate[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
};

/**
 * What POST /conversations/{id}/lead/{lead} answers with.
 *
 * `changed: false` on a 200 is an idempotent success — the thread already named this lead — and is
 * treated exactly like `true`. On a 409 the SAME shape arrives inside the axios error with
 * `lead: null`: the server deliberately does not name the lead that won, so neither does the UI.
 */
export type LinkLeadResponse = {
    changed: boolean;
    lead: InboxLeadSummary | null;
    message?: string;
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

/**
 * Shown when a conversation IS linked but the lead cannot be displayed. Deliberately neutral: the
 * lead may be archived, or simply not visible to this viewer, and the screen must not say which.
 * Shared by the detail header and the list row so the two can never disagree.
 */
export const LINKED_LEAD_UNAVAILABLE = "Linked — lead details unavailable";

/**
 * The four things the conversation header can say about its lead.
 *
 *  - `unlinked_actionable` — not linked, and the server says this viewer may link it.
 *  - `unlinked`            — not linked; no action for this viewer.
 *  - `linked`              — linked to a lead this viewer can see.
 *  - `linked_unavailable`  — linked, but the lead cannot be shown. NOT actionable: `lead_id` is set,
 *                            and a second link would be refused.
 */
export type LeadLinkHeaderState = "unlinked_actionable" | "unlinked" | "linked" | "linked_unavailable";

/**
 * `leadLinked` decides linked vs. unlinked — never `lead !== null`, which is also null on a linked
 * thread whose lead is archived or hidden. And only the server's `link.enabled` makes it actionable:
 * the browser's own permission list cannot see the backend's super_admin bypass. `link` is read
 * defensively so a response without it shows no button rather than crashing.
 */
export function leadLinkHeaderState(
    detail: Pick<InboxConversationDetail, "leadLinked" | "lead" | "link">,
): LeadLinkHeaderState {
    if (detail.leadLinked) {
        return detail.lead !== null ? "linked" : "linked_unavailable";
    }

    return detail.link?.enabled === true ? "unlinked_actionable" : "unlinked";
}
