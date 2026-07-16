<?php

namespace App\Modules\Crm\Services\Mail\Inbound;

/**
 * Normalized, provider-agnostic inbound email — the inbound counterpart to EmailMessage.
 * Every MailboxSyncProvider (Gmail, Microsoft Graph, IMAP) maps its native payload to this
 * shape, so InboundEmailService never sees provider specifics. IDs are stored bare (no <>).
 */
final class InboundMessage
{
    /**
     * @param  list<string>  $references  parsed References chain (bare Message-IDs)
     * @param  array<string, string>  $headers  lowercased header name => value (for auto-reply/bounce detection)
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $mailbox,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly string $toEmail,
        public readonly string $subject,
        public readonly string $bodyText,
        public readonly ?string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references = [],
        public readonly ?string $providerMessageId = null,
        public readonly ?string $providerThreadId = null,
        public readonly ?string $receivedAt = null,
        public readonly array $headers = [],
    ) {}
}
