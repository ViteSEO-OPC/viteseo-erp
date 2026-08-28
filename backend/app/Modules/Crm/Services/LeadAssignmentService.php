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
     * @return bool false when the lead already belonged to that BD — a real occurrence in bulk
     *              handovers, and not something to log a handover Activity for.
     */
    public function assign(Lead $lead, int $assignedTo, User $actor): bool
    {
        // Captured BEFORE the write — from that point on `$lead->assigned_to` is the NEW owner, and
        // the Planner transfer needs to know whose work is being handed over.
        $previousOwnerId = $lead->assigned_to;

        if ($previousOwnerId === $assignedTo) {
            return false;
        }

        DB::transaction(function () use ($lead, $assignedTo, $previousOwnerId, $actor): void {
            $lead->assigned_to = $assignedTo;
            // saveQuietly() skips the generic "updated" Activity — a dedicated lead.assigned
            // event is recorded below (clean attribution for the Management Dashboard).
            $lead->saveQuietly();
            $lead->load('assignedTo');

            $lead->activities()->create([
                'causer_id' => $actor->id,
                'event' => 'lead.assigned',
                'description' => sprintf(
                    '%s assigned %s to %s',
                    $actor->name,
                    $lead->business_name,
                    $lead->assignedTo->name,
                ),
                'properties' => [
                    'assigned_to' => $assignedTo,
                    'to_name' => $lead->assignedTo->name,
                ],
            ]);

            // Open work follows the lead; completed work stays with the BD who performed it.
            $this->plannerOwnership->transferOnReassignment($lead, $previousOwnerId, $assignedTo);

            // ...and, on a FIRST acquisition only, materializes the cold-call follow-up the lead's
            // `cold_calling = scheduled` state implies but could not create while unowned.
            $this->followUps->materializeOnOwnershipAcquired($lead, $previousOwnerId);
        });

        return true;
    }
}
