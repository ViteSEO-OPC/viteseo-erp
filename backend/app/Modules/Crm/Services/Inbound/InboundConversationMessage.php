<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound;

use App\Modules\Crm\Services\Mail\Inbound\InboundMessage;

/**
 * Normalized, provider-agnostic inbound conversation turn — the chat counterpart to
 * {@see InboundMessage}. Every
 * {@see Contracts\InboundConversationProvider} maps its native webhook payload to this shape, so
 * InboundConversationService never sees Messenger / Instagram / WhatsApp specifics.
 *
 * Two omissions are deliberate:
 *
 *  - NO idempotency key. {@see ConversationIdempotency} computes it, so one rule governs every
 *    provider instead of each adapter inventing its own and drifting.
 *  - NO lead id. Identifying the CRM lead is a business decision made by
 *    {@see ConversationLeadMatcher} against the tenant's data, not something an adapter can know
 *    from a payload.
 *
 * `rawPayload` is carried through for preservation in `crm_messages.payload`. It is never logged
 * and is never read for business decisions — the normalized fields above it are the domain model.
 */
final class InboundConversationMessage
{
    /**
     * @param  string  $provider  Vendor/integration: meta, twilio, web, n8n.
     * @param  string  $channel  Medium within that vendor: messenger, instagram_dm, whatsapp, web_chat, lead_ads.
     * @param  string  $externalConversationId  The provider's thread id, or a stable id the adapter synthesized for a threadless channel.
     * @param  string|null  $occurredAt  Provider clock, ISO-8601. Null when the provider sends none.
     * @param  array<string, mixed>  $rawPayload  The provider event exactly as received.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $channel,
        public readonly string $externalConversationId,
        public readonly ?string $externalMessageId = null,
        public readonly ?string $externalEventId = null,
        public readonly ?string $senderExternalId = null,
        public readonly ?string $recipientExternalId = null,
        public readonly ?string $senderName = null,
        public readonly ?string $senderEmail = null,
        public readonly ?string $senderPhone = null,
        public readonly string $messageType = 'text',
        public readonly ?string $text = null,
        public readonly ?string $occurredAt = null,
        public readonly array $rawPayload = [],
        public readonly string $direction = 'inbound',
    ) {}

    public function isInbound(): bool
    {
        return $this->direction === 'inbound';
    }
}
