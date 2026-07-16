<?php

namespace App\Modules\Crm\Events;

use App\Modules\Crm\Models\PlannerEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * RC3 Phase 3 — emitted by the Planner-side reminder emitter (SendDueRemindersJob) when a Planner event's
 * reminder becomes due (`starts_at − reminder_minutes ≤ now`). Planner EMITS this; it does not deliver
 * (FROZEN #4). The pluggable ReminderDeliveryDispatcher delivers it; future channels (email/sms/slack/
 * teams/whatsapp/n8n) subscribe here without any Planner change. Carries no schedule — the "when" was
 * derived from Planner; this only says "a reminder is due for this event".
 */
class PlannerReminderDue
{
    use Dispatchable;

    public function __construct(public readonly PlannerEvent $event) {}
}
