<?php

namespace App\Modules\Crm\Listeners;

use App\Modules\Crm\Events\PlannerReminderDue;
use App\Modules\Crm\Services\Integration\N8nWebhookEmitter;

/**
 * RC3 Phase 5 — emits the `reminder.due` CRM → n8n webhook. Subscribes to the SAME Planner-emitted event
 * as the in-app ReminderDeliveryDispatcher; adding this listener needs no Planner change. n8n consumes
 * the reminder — it never owns the Planner schedule.
 */
class EmitReminderDueWebhook
{
    public function __construct(private readonly N8nWebhookEmitter $emitter) {}

    public function handle(PlannerReminderDue $event): void
    {
        $planner = $event->event;

        $this->emitter->emit('reminder.due', [
            'planner_event_id' => $planner->id,
            'title' => $planner->title,
            'event_type' => $planner->event_type,
            'starts_at' => $planner->starts_at?->toIso8601String(),
            'assigned_to' => $planner->assigned_to,
            'lead_id' => $planner->lead_id,
        ]);
    }
}
