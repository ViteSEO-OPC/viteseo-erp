<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Requests\StorePlannerEventRequest;
use App\Modules\Crm\Requests\UpdatePlannerEventRequest;
use App\Modules\Crm\Resources\PlannerEventResource;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Planner\FollowUpScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Planner events — the single scheduling surface (Calendar + Board). The list is ownership-scoped
 * via PlannerEvent::scopeVisibleTo (BD sees only events assigned to them; Admin/SA see all); single-record
 * routes authorize through PlannerEventPolicy. Every write logs a `planner-event.*` Activity via
 * PlannerEventObserver, so the controller stays thin.
 */
class PlannerEventController extends Controller
{
    /**
     * List planner events visible to the caller. Optional filters power the two representations:
     * calendar views pass ?from/?to (starts_at range); the Board fetches all statuses. ?status
     * and ?event_type narrow further.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $events = PlannerEvent::query()
            ->visibleTo($request->user())
            ->with(['lead', 'owner'])
            ->when($request->filled('from'), fn ($q) => $q->where('starts_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('starts_at', '<=', $request->date('to')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', $request->string('event_type')))
            ->orderByRaw('starts_at IS NULL')       // dated events first, undated (Board backlog) last
            ->orderBy('starts_at')
            ->orderByDesc('id')
            ->get();

        return PlannerEventResource::collection($events);
    }

    /**
     * Create an event. `created_by` = caller; a BD creator auto-owns the event (mirrors lead
     * self-assign), Admin/SA pass the owning BD (enforced by the request). `source` is a controlled
     * domain value: an integrated consumer (e.g. Nearby Clients) may post its own origin, otherwise
     * it defaults to 'manual'. Never accepted as free text — the request validates the enum.
     */
    public function store(StorePlannerEventRequest $request, FollowUpScheduler $followUps): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if ($user->hasRole('crm_bd')) {
            $data['assigned_to'] = $user->id;
        }
        $data['created_by'] = $user->id;

        // Single authoritative creator: a follow-up — from any surface, including this generic
        // endpoint — is minted only by FollowUpScheduler, so the event_type invariant and its
        // defaults can never diverge across callers. Every other type is created here directly.
        if (($data['event_type'] ?? null) === 'follow-up') {
            $lead = isset($data['lead_id']) ? Lead::find($data['lead_id']) : null;
            $event = $followUps->create($lead, $data);
        } else {
            $event = PlannerEvent::create([
                ...$data,
                'source' => $data['source'] ?? 'manual',
            ]);
        }

        return (new PlannerEventResource($event->load(['lead', 'owner'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(PlannerEvent $plannerEvent): JsonResponse
    {
        return (new PlannerEventResource($plannerEvent->load(['lead', 'owner'])))->response();
    }

    /** Partial update — also the Board click-to-move path (a lone `status` change). */
    public function update(UpdatePlannerEventRequest $request, PlannerEvent $plannerEvent): JsonResponse
    {
        $plannerEvent->update($request->validated());

        return (new PlannerEventResource($plannerEvent->load(['lead', 'owner'])))->response();
    }

    public function destroy(PlannerEvent $plannerEvent): JsonResponse
    {
        $plannerEvent->delete();

        return response()->json(null, 204);
    }
}
