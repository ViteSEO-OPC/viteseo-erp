<?php

namespace App\Modules\Crm\Listeners;

use App\Modules\Crm\Events\FollowUpCompleted;
use App\Modules\Crm\Services\Integration\N8nWebhookEmitter;

/**
 * RC3 Outreach Automation Pack — emits the `follow-up.completed` CRM → n8n webhook when a follow-up
 * completes. This is the orchestration/observability surface of the No-Response Escalation workflow;
 * the escalation DECISION and ACTIONS are performed server-side (EscalateUnansweredFollowUp), so the
 * payload is purely factual and n8n evaluates nothing.
 */
class EmitFollowUpCompletedWebhook
{
    public function __construct(private readonly N8nWebhookEmitter $emitter) {}

    public function handle(FollowUpCompleted $event): void
    {
        $plannerEvent = $event->event;

        $this->emitter->emit('follow-up.completed', [
            'planner_event_id' => $plannerEvent->id,
            'lead_id' => $plannerEvent->lead_id,
            'assigned_to' => $plannerEvent->assigned_to,
            'completed_at' => now()->toIso8601String(),
        ]);
    }
}
