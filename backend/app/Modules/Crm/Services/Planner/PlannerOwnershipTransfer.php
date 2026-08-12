<?php

namespace App\Modules\Crm\Services\Planner;

use App\Modules\Crm\Controllers\LeadController;
use App\Modules\Crm\Controllers\LeadQualificationController;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Observers\PlannerEventObserver;
use App\Modules\Crm\Services\LeadCreator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single authority for moving Planner work when a lead changes hands.
 *
 * THE BUSINESS RULE (PO-approved): **open Planner work follows lead ownership; completed Planner
 * work keeps the BD who performed it.** A reassignment hands the new owner everything still owed on
 * that lead, and hands them nothing that was already done — `completed_at` plus the Activity trail
 * are the record of who actually did the work, and reassignment must never rewrite that.
 *
 * WHY THIS IS A SERVICE AND NOT AN OBSERVER. The obvious design — react to `leads.assigned_to`
 * changing in LeadObserver — cannot work: {@see LeadController::assign()} persists the handover with
 * `saveQuietly()` (deliberately, so the dedicated `lead.assigned` Activity is the only one logged),
 * and a quiet save fires no model events at all. An observer-based transfer would therefore silently
 * skip the single most common reassignment path. Call sites invoke this class explicitly.
 *
 * WHY EVERY MOVE IS A NORMAL `update()`. {@see PlannerEventObserver} must fire, so each transfer
 * lands in the append-only Activity trail as `planner-event.reassigned` (carrying from/to). A bulk
 * query-builder `UPDATE` would be faster and would silently produce no audit at all — exactly the
 * class of invisible ownership change this service exists to end. Volumes are per-lead, so the row
 * loop is never the bottleneck.
 *
 * NOT A CREATION-TIME CONCERN. Paths that set `assigned_to` while CREATING a lead
 * ({@see LeadCreator}, Excel import, scraper ingest) never need this: a
 * lead that does not exist yet cannot have Planner events. Those call sites deliberately do not
 * invoke it — a call that provably always returns 0 would imply a behaviour it does not have.
 */
class PlannerOwnershipTransfer
{
    /**
     * Move a lead's OPEN Planner work from its previous owner to its new one.
     *
     * Called by every path that REASSIGNS an existing lead: {@see LeadController::assign()} (Admin
     * hands a lead to a BD) and {@see LeadQualificationController} (a BD qualifies a discovered
     * prospect and takes ownership).
     *
     * Selection is deliberately NARROW — only events whose owner is the PREVIOUS lead owner move.
     * An event Admin deliberately assigned to a third BD (say, a specialist booked for one meeting)
     * is left exactly where it is: it was never following lead ownership, so a handover of the lead
     * is not a handover of that commitment.
     *
     * @param  int|null  $previousOwnerId  The lead's owner BEFORE the write — capture it before the
     *                                     model is mutated, since `$lead->assigned_to` is by then the
     *                                     NEW owner.
     * @return int number of events transferred (0 when nothing qualifies)
     */
    public function transferOnReassignment(Lead $lead, ?int $previousOwnerId, ?int $newOwnerId): int
    {
        // Nothing to transfer FROM: an unassigned lead's events belong to whoever holds them (they
        // were never following an owner), so taking ownership of the lead does not claim them.
        if ($previousOwnerId === null) {
            return 0;
        }

        // Never orphan work by moving it to nobody. `assign()` cannot reach this (AssignLeadRequest
        // requires a BD), but qualification and any future caller must not be able to either.
        if ($newOwnerId === null) {
            return 0;
        }

        // A no-op reassignment — the same BD re-selected. Real data contains these.
        if ($previousOwnerId === $newOwnerId) {
            return 0;
        }

        $events = $this->openWork(PlannerEvent::query())
            ->where('lead_id', $lead->id)
            ->where('assigned_to', $previousOwnerId)
            ->get();

        foreach ($events as $event) {
            // Normal save on purpose — see the class docblock. The observer turns this into a
            // `planner-event.reassigned` Activity; nothing else about the event changes, so no
            // completion is stamped and no FollowUpCompleted is dispatched.
            $event->update(['assigned_to' => $newOwnerId]);
        }

        return $events->count();
    }

    /**
     * The single definition of "open Planner work" for ownership purposes.
     *
     * `status <> 'completed'` is the completed-history protection, expressed ONCE so the forward path
     * and the backfill command cannot drift apart on what they are allowed to touch.
     *
     * Soft-deleted events are excluded already — PlannerEvent uses SoftDeletes, so its global scope
     * keeps them out of every query here. An archived event is nobody's outstanding work, and that
     * is enforced by the model rather than restated (and risked) in each caller.
     *
     * Note this is NOT {@see PlannerEvent::scopeFollowUpWork}: ownership is not a KPI question.
     * EVERY open event on the lead transfers, `task` and `consultation` included — a task the new
     * owner must do is theirs regardless of whether any KPI bucket happens to report it.
     *
     * @param  Builder<PlannerEvent>  $query
     * @return Builder<PlannerEvent>
     */
    private function openWork(Builder $query): Builder
    {
        return $query->where('status', '!=', 'completed');
    }
}
