<?php

namespace App\Modules\Crm\Events;

use App\Modules\Crm\Models\PlannerEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * RC3 Outreach Automation Pack — raised when a follow-up PlannerEvent transitions to `completed`
 * (PlannerEventObserver). A domain seam (FROZEN #6) with two additive consumers: the
 * `follow-up.completed` CRM → n8n webhook (orchestration/observability) and the server-side
 * No-Response Escalation listener (Laravel decides). Planner remains the owner of completion — this
 * event observes the transition, it does not perform or alter it.
 */
class FollowUpCompleted
{
    use Dispatchable;

    public function __construct(public readonly PlannerEvent $event) {}
}
