<?php

namespace App\Modules\Crm\Observers;

use App\Modules\Crm\Events\FollowUpCompleted;
use App\Modules\Crm\Models\PlannerEvent;
use Illuminate\Support\Facades\Auth;

/**
 * Records an append-only Activity for every planner event create/update/delete, mirroring
 * NoteObserver. The Activity subject is the event's LEAD when one is linked (so the event shows
 * on that lead's timeline and flows through Activity::scopeVisibleTo via lead ownership); when
 * the event has no lead, the event itself is the subject (visible to its causer). The acting
 * user is the causer. No new activity system — this realizes Planner Event → Activity Log.
 */
class PlannerEventObserver
{
    /**
     * Stamp the real completion time on the transition into `completed`, and clear it if the event is
     * reopened — the single source of `completed_at` (never `updated_at`). Runs before every save
     * (create or update), so every completion path (Planner update, FollowUpScheduler, CallSession's
     * event completion) is covered without each one remembering to set it. A no-op edit to an
     * already-completed event preserves its original completion time.
     */
    public function saving(PlannerEvent $event): void
    {
        if ($event->status === 'completed') {
            if ($event->completed_at === null) {
                $event->completed_at = now();
            }
        } elseif ($event->completed_at !== null) {
            $event->completed_at = null;
        }
    }

    public function created(PlannerEvent $event): void
    {
        $this->log($event, 'planner-event.created', 'Event scheduled');
    }

    public function updated(PlannerEvent $event): void
    {
        // A transition into `completed` is logged as its own semantic event (the KPI Engine and
        // BD Performance will care about completions); every other change is a generic update.
        if ($event->wasChanged('status') && $event->status === 'completed') {
            $this->log($event, 'planner-event.completed', 'Event completed');

            // RC3 Outreach Automation Pack — completed FOLLOW-UP WORK is a domain event (FROZEN #6): it
            // feeds the `follow-up.completed` n8n webhook (observability) and the server-side
            // No-Response Escalation. Now the whole category ({@see PlannerEvent::scopeFollowUpWork}),
            // per the PO rule that completed Email/Call follow-up work stays escalation-eligible; `task`
            // and `consultation` still just log completion.
            //
            // PLANNED != PERFORMED is preserved by the trigger, not by the type set: this fires only on
            // a transition INTO `completed`, so merely scheduling an event can never escalate. What
            // counts as performing the work stays each channel's own decision — CallSessionService
            // completes a `call` event only on a CONNECTED outcome, and an `email` task is completed by
            // an actual send or a deliberate manual Mark Done.
            if (in_array($event->event_type, PlannerEvent::FOLLOW_UP_WORK_TYPES, true)) {
                event(new FollowUpCompleted($event));
            }

            return;
        }

        // An ownership handover is its own semantic event, not a generic edit. It is logged with the
        // from/to pair because "who owes this work" is the one field whose history the Activity trail
        // could not otherwise reconstruct — every other change is recoverable from the current row.
        // Emitted by PlannerOwnershipTransfer (lead reassignment) and by an Admin PATCHing
        // `assigned_to` directly; both are the same business fact, so both read the same way.
        if ($event->wasChanged('assigned_to')) {
            $this->log($event, 'planner-event.reassigned', 'Event reassigned', [
                'from' => $event->getOriginal('assigned_to'),
                'to' => $event->assigned_to,
            ]);

            return;
        }

        $this->log($event, 'planner-event.updated', 'Event updated');
    }

    public function deleted(PlannerEvent $event): void
    {
        $this->log($event, 'planner-event.deleted', 'Event removed');
    }

    /**
     * @param  array<string, mixed>|null  $extra  Event-specific properties merged over the shared
     *                                            three (never replacing them).
     */
    private function log(PlannerEvent $event, string $activity, string $description, ?array $extra = null): void
    {
        $subject = $event->lead ?? $event;

        $subject->activities()->create([
            'causer_id' => Auth::id(),
            'event' => $activity,
            'description' => $description,
            'properties' => [
                'planner_event_id' => $event->id,
                'event_type' => $event->event_type,
                'title' => $event->title,
                ...($extra ?? []),
            ],
        ]);
    }
}
