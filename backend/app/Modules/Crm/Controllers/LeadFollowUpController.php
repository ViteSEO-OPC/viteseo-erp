<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Requests\StoreLeadFollowUpRequest;
use App\Modules\Crm\Resources\PlannerEventResource;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Planner\FollowUpScheduler;
use Illuminate\Http\JsonResponse;

/**
 * Lead Details → "Schedule follow-up" Quick Action. Creates a follow-up PlannerEvent for a lead
 * through the ONE authoritative creator ({@see FollowUpScheduler}) — the same component the Cold
 * Calling → Scheduled trigger and the generic planner endpoint use, so a follow-up never has a
 * second creation path. This controller only resolves the HTTP intent (status from date, manual
 * provenance) and delegates; it never calls PlannerEvent::create itself.
 */
class LeadFollowUpController extends Controller
{
    public function store(StoreLeadFollowUpRequest $request, Lead $lead, FollowUpScheduler $followUps): JsonResponse
    {
        $data = $request->validated();

        $event = $followUps->create($lead, [
            ...$data,
            // A dated follow-up surfaces on the calendar (scheduled); a bare one is a To-Do.
            'status' => empty($data['starts_at']) ? 'todo' : 'scheduled',
            // BD-initiated from the lead — distinct provenance from the 'outreach' auto-trigger.
            'source' => 'manual',
            // Ownership/causer default inside FollowUpScheduler (lead owner / acting user).
        ]);

        return (new PlannerEventResource($event->load(['lead', 'owner'])))
            ->response()
            ->setStatusCode(201);
    }
}
