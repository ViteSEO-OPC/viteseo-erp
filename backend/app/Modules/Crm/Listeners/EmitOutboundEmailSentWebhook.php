<?php

namespace App\Modules\Crm\Listeners;

use App\Modules\Crm\Events\OutboundEmailSent;
use App\Modules\Crm\Services\Integration\N8nWebhookEmitter;

/**
 * RC3 Phase 5 — emits the `email.sent` CRM → n8n webhook when an outbound email is sent. Read-only
 * observer of the send; no send logic here (all sending stays in OutreachEmailService → EmailProvider).
 */
class EmitOutboundEmailSentWebhook
{
    public function __construct(private readonly N8nWebhookEmitter $emitter) {}

    public function handle(OutboundEmailSent $event): void
    {
        $email = $event->email;

        $this->emitter->emit('email.sent', [
            'email_id' => $email->id,
            'lead_id' => $email->lead_id,
            'subject' => $email->subject,
            'to' => $email->to_email,
            'provider' => $email->provider,
            'sent_at' => $email->sent_at?->toIso8601String(),
        ]);
    }
}
