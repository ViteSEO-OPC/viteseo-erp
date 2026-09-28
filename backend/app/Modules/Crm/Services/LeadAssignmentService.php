<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services;

use App\Models\User;
use App\Modules\Crm\Controllers\LeadController;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Planner\FollowUpScheduler;
use App\Modules\Crm\Services\Planner\PlannerOwnershipTransfer;
use Illuminate\Support\Facades\DB;

/**
 * The one place a lead changes hands.
 *
 * Extracted verbatim from {@see LeadController::assign()} so a BULK handover cannot become a second,
 * quieter ownership model. A bulk `UPDATE crm_leads SET assigned_to = ?` would be faster and would
 * produce: no `lead.assigned` Activity, no Planner transfer, no cold-call reconciliation — an
 * ownership change invisible to every audit surface the CRM has. Both the single-lead endpoint and
 * the resignation handover call THIS, so the two can never drift.
 *
 * WHAT MOVES: `crm_leads.assigned_to` and the lead's OPEN Planner work.
 * WHAT DOES NOT: completed Planner events, calls, notes, emails, activities, `qualified_by`,
 * `created_by`, scores and performance history — the permanent record of who did the work. That
 * split is {@see PlannerOwnershipTransfer}'s frozen rule, not a new one invented here.
 *
 * Notification and broadcast are deliberately NOT in this class. They stay with the caller because
 * they must fire after the commit, and because their shape differs per caller: one bell per lead is
 * the right announcement for a single handover and thirteen bells is noise for a resignation.
 */
class LeadAssignmentService
{
    public function __construct(
        private readonly PlannerOwnershipTransfer $plannerOwnership,
        private readonly FollowUpScheduler $followUps,
    ) {}

    /**
     * Hand one lead to a BD, atomically.
     *
     * One transaction around the handover: the ownership row, its Activity, and the open Planner work
     * land together or not at all. A partial failure is precisely the defect the Planner transfer
     * exists to fix — a lead that changed hands while its outstanding work stayed pointed at the
     * previous BD, invisible to the person now accountable for it.
     *
     * `$assignedTo` may be NULL, which CLEARS the assignment — a lead handed back to nobody while
     * an owner is decided. It is the same operation as any other handover and takes the same path:
     * one transaction, one `lead.assigned` Activity, the same Planner rule. It is deliberately NOT a
     * separate "unassign" method, because a second method is a second place for ownership to change.
     *
     * @param  int|null  $assignedTo  the new owner, or null to clear the assignment
     * @return bool false when the lead already belonged to that BD (judged on the locked, committed
     *              row) — a real occurrence in bulk handovers and repeat submits, and not something
     *              to log a handover Activity for.
     */
    public function assign(Lead $lead, ?int $assignedTo, User $actor): bool
    {
        // LOCKED RE-READ (CEO audit crm-14c). `$lead` was read before this call — by route-model
        // binding, or by the resignation handover's portfolio query — so its `assigned_to` can be
        // stale by the time the handover writes. Trusting it let two overlapping handovers both run:
        // the second overwrote the owner the first had just committed, logged a second
        // `lead.assigned`, and moved open Planner work FROM an owner who no longer held it, stranding
        // that work with the BD who had just lost the lead. Re-reading the row under `lockForUpdate`
        // makes a competing handover wait for this one to commit, and every decision below is then
        // made against the committed owner.
        return DB::transaction(function () use ($lead, $assignedTo, $actor): bool {
            /** @var Lead|null $locked */
            $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                // Archived between the caller's read and the lock: there is nothing to hand over.
                return false;
            }

            // Captured BEFORE the write — from that point on `assigned_to` is the NEW owner, and the
            // Planner transfer needs to know whose work is being handed over.
            $previousOwnerId = $locked->assigned_to;

            if ($previousOwnerId === $assignedTo) {
                // Callers render `$lead`, so they still get the committed owner when nothing moved.
                $this->syncCallerInstance($lead, $locked);

                return false;
            }

            $locked->assigned_to = $assignedTo;
            // saveQuietly() skips the generic "updated" Activity — a dedicated lead.assigned
            // event is recorded below (clean attribution for the Management Dashboard).
            $locked->saveQuietly();
            $this->syncCallerInstance($lead, $locked);

            // `assignedTo` is null when the assignment was cleared, so the name is resolved rather
            // than dereferenced. The event stays `lead.assigned`: clearing an owner IS an ownership
            // change, and splitting it into a second event type would hide it from every audit
            // surface that reads this one.
            $newOwnerName = $lead->assignedTo?->name;

            $lead->activities()->create([
                'causer_id' => $actor->id,
                'event' => 'lead.assigned',
                'description' => $newOwnerName === null
                    ? sprintf('%s removed the owner from %s', $actor->name, $lead->business_name)
                    : sprintf('%s assigned %s to %s', $actor->name, $lead->business_name, $newOwnerName),
                'properties' => [
                    'assigned_to' => $assignedTo,
                    'to_name' => $newOwnerName,
                ],
            ]);

            // Open work follows the lead; completed work stays with the BD who performed it.
            $this->plannerOwnership->transferOnReassignment($lead, $previousOwnerId, $assignedTo);

            // ...and, on a FIRST acquisition only, materializes the cold-call follow-up the lead's
            // `cold_calling = scheduled` state implies but could not create while unowned.
            $this->followUps->materializeOnOwnershipAcquired($lead, $previousOwnerId);

            return true;
        });
    }

    /**
     * Point the caller's instance at the committed owner. The write goes through the locked row, so
     * without this the caller would keep rendering (and notifying) the owner it read before the lock.
     */
    private function syncCallerInstance(Lead $lead, Lead $locked): void
    {
        $lead->forceFill([
            'assigned_to' => $locked->assigned_to,
            'updated_at' => $locked->updated_at,
        ]);
        $lead->syncOriginalAttributes(['assigned_to', 'updated_at']);
        $lead->load('assignedTo');
    }
}
