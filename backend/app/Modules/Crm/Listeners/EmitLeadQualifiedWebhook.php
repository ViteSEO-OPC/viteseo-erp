<?php

namespace App\Modules\Crm\Listeners;

use App\Modules\Crm\Events\LeadQualified;
use App\Modules\Crm\Services\Integration\N8nWebhookEmitter;

/**
 * RC3 Outreach Automation Pack — emits the `lead.qualified` CRM → n8n webhook when a prospect is
 * qualified into the CRM. Drives the Introduction Email workflow. Payload carries only what the
 * orchestration needs (lead + assigned BD); n8n triggers CRM actions, it never evaluates lead state.
 */
class EmitLeadQualifiedWebhook
{
    public function __construct(private readonly N8nWebhookEmitter $emitter) {}

    public function handle(LeadQualified $event): void
    {
        $lead = $event->lead;

        $this->emitter->emit('lead.qualified', [
            'lead_id' => $lead->id,
            'business_name' => $lead->business_name,
            'assigned_to' => $lead->assigned_to,
            'qualified_at' => $lead->qualified_at?->toIso8601String(),
        ]);
    }
}
