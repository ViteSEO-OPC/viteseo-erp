<?php

namespace App\Modules\Crm\Services\Mail;

/**
 * Immutable, transport-agnostic outbound email. Carries only what any provider
 * needs to send — no SMTP / Gmail / Graph specifics leak into the Outreach layer.
 */
final class EmailMessage
{
    public function __construct(
        public readonly string $toEmail,
        public readonly ?string $toName,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $fromEmail,
        public readonly string $fromName,
    ) {}
}
