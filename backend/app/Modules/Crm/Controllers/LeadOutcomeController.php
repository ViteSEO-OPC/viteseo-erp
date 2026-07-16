<?php

namespace App\Modules\Crm\Controllers;

use App\Modules\Crm\Events\DealWon;
use App\Modules\Crm\Requests\MarkLeadLostRequest;
use App\Modules\Crm\Requests\MarkLeadWonRequest;
use App\Modules\Crm\Resources\LeadResource;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Illuminate\Http\JsonResponse;

/**
 * BD Lead Detail sales-outcome actions: "Mark as Won" and "Mark as Lost".
 *
 * Architecture (KB: Lead-Quick-Actions-Architecture):
 *  - Won is a DEAL concern: it creates a Deal (successful business only). Revenue and Conversion
 *    continue to be sourced EXCLUSIVELY from Deal records — this action writes a Deal, it does not
 *    change any computation. It touches no lead column, so the Lead Score is not recomputed.
 *  - Lost is a LEAD workflow Activity only: it records a `lead.lost` Activity (with an optional
 *    reason in `properties`) and completes outstanding follow-ups. It creates NO Deal, changes NO
 *    lead column, and therefore leaves Revenue, Conversion, and the Lead Score untouched — the
 *    single deterministic scoring pipeline ({@see LeadScorer::refresh}) stays the sole score writer.
 *
 * Both mirror LeadController::assign() — a dedicated custom action that writes an explicit,
 * append-only Activity via the lead's `activities()` morph.
 */
class LeadOutcomeController extends Controller
{
    /**
     * Mark a lead as Won → create a Deal + a `lead.won` Activity.
     * One active Deal per lead (repeat-business multi-deal is the future Opportunity pipeline).
     */
    public function won(MarkLeadWonRequest $request, Lead $lead): JsonResponse
    {
        // Duplicate-Deal guard: a lead already tied to a Deal is already "won".
        if ($lead->deals()->exists()) {
            return response()->json(['message' => 'This lead is already marked as won.'], 409);
        }

        // Realized amount defaults to the lead's prospect-stage estimate (leads.amount) when omitted.
        $amount = $request->validated('amount');
        $amount = $amount !== null ? (float) $amount : (float) ($lead->amount ?? 0);

        // Deal creation touches no lead column → LeadObserver does not fire → Lead Score unchanged.
        $deal = $lead->deals()->create([
            'amount' => $amount,
            'converted_at' => now(),
        ]);

        $lead->activities()->create([
            'causer_id' => $request->user()->id,
            'event' => 'lead.won',
            'description' => sprintf('%s marked %s as won', $request->user()->name, $lead->business_name),
            'properties' => ['deal_id' => $deal->id, 'amount' => $amount],
        ]);

        // RC3 Outreach Automation Pack — announce the outcome as a domain event (FROZEN #6) so the
        // `deal.won` n8n webhook (Thank You Email workflow) can react. Additive: Revenue/Conversion
        // still source exclusively from the Deal; no computation or lead column changes.
        event(new DealWon($lead, $deal));

        return response()->json(['data' => new LeadResource($lead->fresh())], 201);
    }

    /**
     * Mark a lead as Lost → a `lead.lost` Activity only (NO Deal, NO status change, NO schema change).
     * Optionally completes the lead's outstanding follow-ups via the existing Planner workflow.
     */
    public function lost(MarkLeadLostRequest $request, Lead $lead): JsonResponse
    {
        $reason = $request->validated('reason');

        // Activity insert only — touches no scoring input, so the Lead Score is not recomputed.
        $lead->activities()->create([
            'causer_id' => $request->user()->id,
            'event' => 'lead.lost',
            'description' => sprintf('%s marked %s as lost', $request->user()->name, $lead->business_name),
            'properties' => $reason !== null ? ['reason' => $reason] : null,
        ]);

        // Close outstanding follow-ups through the existing Planner workflow — updating the
        // PlannerEvent status fires PlannerEventObserver (planner-event.completed). No new
        // scheduling logic; this is the same completion path the Planner Board uses.
        PlannerEvent::query()
            ->where('lead_id', $lead->id)
            ->where('event_type', 'follow-up')
            ->where('status', '!=', 'completed')
            ->get()
            ->each(fn (PlannerEvent $event) => $event->update(['status' => 'completed']));

        return response()->json(['data' => new LeadResource($lead->fresh())], 201);
    }
}
