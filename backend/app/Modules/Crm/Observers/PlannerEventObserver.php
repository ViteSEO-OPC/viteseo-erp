<?php

namespace App\Modules\Crm\Observers;

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

            return;
        }

        $this->log($event, 'planner-event.updated', 'Event updated');
    }

    public function deleted(PlannerEvent $event): void
    {
        $this->log($event, 'planner-event.deleted', 'Event removed');
    }

    private function log(PlannerEvent $event, string $activity, string $description): void
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
            ],
        ]);
    }
}
