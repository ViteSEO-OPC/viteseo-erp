<?php

namespace App\Modules\Crm\Jobs;

use App\Modules\Crm\Events\PlannerReminderDue;
use App\Modules\Crm\Models\PlannerEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RC3 Phase 3 — the Planner-side reminder emitter. Runs every minute on the EXISTING Laravel scheduler
 * (no new scheduler — FROZEN #9). It READS Planner's schedule and emits PlannerReminderDue for each event
 * whose reminder is due; the ReminderDeliveryDispatcher delivers it. The reminder's "when" is DERIVED
 * from Planner (`starts_at − reminder_minutes`) — this job owns no schedule state.
 *
 * Planner is never modified: the "already delivered" guard is a `whereNotExists` subquery against
 * `reminder_deliveries` (no relation added to PlannerEvent).
 */
class SendDueRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $now = now();

        $due = PlannerEvent::query()
            ->whereNotNull('reminder_minutes')
            ->whereNotNull('starts_at')
            ->where('status', '!=', 'completed')                 // pending events only
            ->where('starts_at', '>=', $now)                     // upcoming (not already started)
            ->whereRaw('starts_at <= DATE_ADD(?, INTERVAL reminder_minutes MINUTE)', [$now]) // due = starts_at - reminder_minutes <= now
            ->whereNotExists(function ($q) {
                // Skip events that already have a delivery — Planner model stays untouched.
                $q->select(DB::raw(1))
                    ->from('crm_reminder_deliveries')
                    ->whereColumn('crm_reminder_deliveries.planner_event_id', 'crm_planner_events.id');
            })
            ->orderBy('starts_at')
            ->limit(200)
            ->get();

        foreach ($due as $event) {
            event(new PlannerReminderDue($event)); // Planner emits; the dispatcher delivers (FROZEN #4)
        }

        if ($due->isNotEmpty()) {
            Log::info('planner.reminder.emitted', ['count' => $due->count()]);
        }
    }
}
