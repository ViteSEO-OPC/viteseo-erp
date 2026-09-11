<?php

namespace App\Modules\Crm\Services\Mail;

/**
 * Immutable, transport-agnostic outbound email. Carries only what any provider
 * needs to send — no SMTP / Gmail / Graph specifics leak into the Outreach layer.
 *
 * `body` is always the plain text and always sent. `htmlBody` is optional and additive: when present
 * the provider sends it ALONGSIDE the text as a second part (multipart/alternative), never instead of
 * it. It is only ever produced by {@see EmailDocument::toHtml()} from a validated document — never
 * taken from a client.
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
        public readonly ?string $htmlBody = null,
    ) {}
}
