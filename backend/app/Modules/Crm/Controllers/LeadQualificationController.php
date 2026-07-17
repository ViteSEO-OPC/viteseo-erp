<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Resources\LeadResource;
use App\Modules\Crm\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function store(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('qualify', $lead);

        // Only discovered businesses are qualifiable — a manually-created / imported lead is already
        // an active CRM lead, not a prospect.
        if ($lead->source === null) {
            return response()->json([
                'message' => 'This lead is already an active CRM lead — only discovered prospects can be qualified.',
            ], 422);
        }

        if ($lead->qualified_at !== null) {
            return response()->json([
                'message' => 'This prospect has already been qualified into the CRM.',
            ], 409);
        }

        // Cross the boundary: stamp the lifecycle + take ownership. The observer detects the
        // qualified_at transition → logs `lead.qualified` and computes the Lead Score once.
        $lead->update([
            'qualified_at' => now(),
            'qualified_by' => $request->user()->id,
            'assigned_to' => $request->user()->id,
        ]);

        return response()->json([
            'data' => new LeadResource($lead->refresh()),
        ], 201);
    }
}
