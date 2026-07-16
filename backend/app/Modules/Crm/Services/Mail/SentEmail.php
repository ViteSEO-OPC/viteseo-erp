<?php

namespace App\Modules\Crm\Services\Mail;

/**
 * Result of a provider send. `messageId` is the RFC 5322 Message-ID actually sent (bare,
 * no angle brackets) — persisted so inbound replies can thread back to this email.
 * `providerMessageId` is the provider's own id (e.g. Gmail message id), null for plain SMTP.
 */
final class SentEmail
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $messageId = null,
        public readonly ?string $providerMessageId = null,
        public readonly string $status = 'sent',
    ) {}
}
