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

export type InboxMessage = {
    id: string;
    direction: "inbound" | "outbound";
    messageType: string;
    body: string | null;
    hasAttachment: boolean;
    occurredAt: string | null;
    receivedAt: string | null;
    processedAt: string | null;
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
