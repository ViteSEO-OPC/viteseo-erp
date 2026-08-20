<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Resources\LeadResource;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Planner\FollowUpScheduler;
use App\Modules\Crm\Services\Planner\PlannerOwnershipTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The qualification bridge between the two bounded contexts: Prospect Pool → Active CRM Lead.
 *
 * A discovered business (Nearby Clients) is an unqualified PROSPECT and takes no part in Business
 * Computation. Qualifying it accepts it into the CRM sales pipeline: it gains an owner and, from this
 * moment, is scored and counted in dashboards / performance / pipeline. This is the ONLY path that
 * moves a lead across the boundary — the single, explicit bridge in the architecture.
 *
 * Deliberately narrow: it sets lifecycle + ownership only. The Lead Score is (re)computed by the
 * observer on the `qualified_at` transition — never here, and never during discovery/enrichment.
 */
class LeadQualificationController extends Controller
{
    public function store(
        Request $request,
        Lead $lead,
        PlannerOwnershipTransfer $plannerOwnership,
        FollowUpScheduler $followUps,
    ): JsonResponse {
        $this->authorize('qualify', $lead);

        // ATOMIC CLAIM. The prospect pool is shared and unassigned, so two BDs can click "Add to
        // Leads" on the same row at the same moment. Reading the route-bound instance and checking
        // `qualified_at` on it is a time-of-check/time-of-use gap: both requests could see NULL,
        // both could pass the guard, and the second UPDATE would silently overwrite the first
        // claimant's ownership — while BOTH callers received 201, and the observer fired twice
        // (two `lead.qualified` Activities, two scoring runs).
        //
        // Re-reading the row INSIDE a transaction with `lockForUpdate` closes that window: the
        // second request blocks on the row lock until the first commits, then re-reads the now-set
        // `qualified_at` and takes the 409 branch. The guards below therefore run against `$locked`
        // — the freshly locked row — never against the instance bound before the lock existed.
        //
        // Deliberately an Eloquent `update()` on a locked model rather than a conditional
        // `UPDATE … WHERE qualified_at IS NULL` via the query builder: a query-builder write fires
        // no model events, which would bypass LeadObserver entirely and silently drop the
        // `lead.qualified` Activity, the one-time Lead Score, and the LeadQualified event.
        return DB::transaction(function () use ($request, $lead, $plannerOwnership, $followUps): JsonResponse {
            /** @var Lead|null $locked */
            $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                // Deleted between route binding and the lock.
                return response()->json(['message' => 'Lead not found.'], 404);
            }

            // Only discovered businesses are qualifiable — a manually-created / imported lead is
            // already an active CRM lead, not a prospect.
            if ($locked->source === null) {
                return response()->json([
                    'message' => 'This lead is already an active CRM lead — only discovered prospects can be qualified.',
                ], 422);
            }

            if ($locked->qualified_at !== null) {
                return response()->json([
                    'message' => 'This prospect has already been qualified into the CRM.',
                ], 409);
            }

            // Captured before the write — a prospect is normally unassigned (the pool is shared), in
            // which case the transfer below is a documented no-op. It is NOT always null: a lead can
            // be assigned at creation and qualified later, and that lead may already carry auto-created
            // follow-up work (FollowUpScheduler mints one the moment Cold Calling is set to Scheduled).
            $previousOwnerId = $locked->assigned_to;

            // Cross the boundary: stamp the lifecycle + take ownership. The observer detects the
            // qualified_at transition → logs `lead.qualified` and computes the Lead Score once.
            $locked->update([
                'qualified_at' => now(),
                'qualified_by' => $request->user()->id,
                'assigned_to' => $request->user()->id,
            ]);

            // Taking ownership takes the outstanding work with it — the same rule as an Admin
            // reassignment, through the same service, inside the lock that already guards this claim.
            $plannerOwnership->transferOnReassignment($locked, $previousOwnerId, $request->user()->id);

            // ...and, for a prospect that never had an owner, materializes the cold-call follow-up its
            // `cold_calling = scheduled` state implies but could not create while unowned. The exact
            // call LeadController::assign() makes, so the two ownership-acquisition paths cannot
            // diverge — a prospect must not carry different Planner work depending on whether an Admin
            // assigned it or a BD claimed it.
            $followUps->materializeOnOwnershipAcquired($locked, $previousOwnerId);

            // BR-NQ-01 (PO-approved) — the first-touch Todo. A discovered prospect can never arrive
            // `cold_calling = 'scheduled'` (IngestDiscoveredLeadsRequest rejects the field, and the
            // column defaults to 'not-called'), so the line above is a documented no-op for the
            // Nearby path and the lead would otherwise land in the CRM owned, scored, and with no
            // work attached to it — invisible to Outreach, which is derived purely from pending
            // Planner follow-up/email events.
            //
            // Runs AFTER materializeOnOwnershipAcquired so the two can never both fire: that call
            // creates an open follow-up for an already-`scheduled` prospect, which this one's dedupe
            // then sees and declines. Order is the guarantee of "exactly one", not a coincidence.
            //
            // BD-only, and it mints a Planner row ONLY — no email is created or scheduled here; see
            // the method docblock for why that is structural rather than conventional.
            $followUps->scheduleFirstTouch($locked, $request->user());

            return response()->json([
                'data' => new LeadResource($locked->refresh()),
            ], 201);
        });
    }
}
