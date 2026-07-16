<?php

namespace App\Modules\Crm\Listeners;

use App\Modules\Crm\Events\ProposalSent;
use App\Modules\Crm\Services\Integration\N8nWebhookEmitter;

/**
 * RC3 Outreach Automation Pack — emits the `proposal.sent` CRM → n8n webhook when a proposal email is
 * sent. Read-only observer of the send (all sending stays in OutreachEmailService → EmailProvider).
 * Drives the Proposal Follow-up workflow (n8n schedules the follow-up through Planner; the CRM later
 * decides whether to send it based on reply state).
 */
class EmitProposalSentWebhook
{
    public function __construct(private readonly N8nWebhookEmitter $emitter) {}

    public function handle(ProposalSent $event): void
    {
        $email = $event->email;

        $this->emitter->emit('proposal.sent', [
            'email_id' => $email->id,
            'lead_id' => $email->lead_id,
            // The lead's owning BD — n8n needs it to schedule the follow-up Planner event (Planner owns
            // the "when"; assigned_to must be a Business Developer per StorePlannerEventRequest).
            'assigned_to' => $email->lead?->assigned_to,
            'purpose' => $email->purpose,
            'subject' => $email->subject,
            'sent_at' => $email->sent_at?->toIso8601String(),
        ]);
    }
}
