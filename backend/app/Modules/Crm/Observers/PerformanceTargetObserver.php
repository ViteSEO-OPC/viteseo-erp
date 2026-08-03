<?php

namespace App\Modules\Crm\Observers;

use App\Modules\Crm\Models\PerformanceTarget;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the user-visible activity trail for Goal Management mutations, satisfying the requirement
 * that "all operations shall be recorded in the activity log".
 *
 * Deliberately RECORDS THE FACT, NOT THE PAYLOAD. `properties` carries the subject, metric, period
 * and value — enough to answer "who changed which goal, when" in the Activity Logs feed — and no
 * before/after diff. crm_business_rule_audit is the place for full before/after records; the
 * activity feed is an operational log, and stuffing diffs into it would blur two systems that were
 * deliberately kept separate.
 *
 * Visibility note: Activity::scopeVisibleTo returns everything to crm_admin/super_admin, so both
 * management roles see these entries — which matches who may create them, since Admin and Super
 * Admin both hold `crm.performance.manage`. The actor is recorded from the authenticated user, so
 * the trail distinguishes the two.
 */
class PerformanceTargetObserver
{
    public function created(PerformanceTarget $target): void
    {
        $this->log($target, 'performance-target.created', 'Performance target set');
    }

    public function updated(PerformanceTarget $target): void
    {
        // A soft delete arrives here as an update; report it as the retirement it is.
        if ($target->wasChanged('deleted_at') && $target->deleted_at !== null) {
            return;
        }

        $this->log($target, 'performance-target.updated', 'Performance target updated');
    }

    public function deleted(PerformanceTarget $target): void
    {
        $this->log($target, 'performance-target.deleted', 'Performance target removed');
    }

    private function log(PerformanceTarget $target, string $event, string $description): void
    {
        $target->activities()->create([
            'causer_id' => Auth::id(),
            'event' => $event,
            'description' => $description,
            'properties' => [
                'target_user_id' => $target->user_id,
                'metric' => $target->metric->value,
                'period_type' => $target->period_type->value,
                'period_start' => $target->period_start?->toDateString(),
                'target_value' => $target->target_value,
            ],
        ]);
    }
}
