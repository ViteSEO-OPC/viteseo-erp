<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Enums\CallOutcome;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Requests\CompleteCallRequest;
use App\Modules\Crm\Resources\CallResource;
use App\Modules\Crm\Services\Calling\CallSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The Calling Workspace HTTP surface. Thin by design: EVERY state change delegates to
 * {@see CallSessionService} (the sole orchestration layer) — the controller only resolves the HTTP
 * intent and shapes the response, and NEVER persists a Call itself. Reading/starting authorize through
 * the parent lead or planner event (ownership boundary); completing/discarding authorize through
 * CallPolicy. A blocked domain rule (single-active-call, already-concluded) surfaces from the service as
 * a 422 via ValidationException — the standard `{message, errors}` envelope.
 */
class CallController extends Controller
{
    public function __construct(private readonly CallSessionService $calls) {}

    /** Call History for a lead — every attempt across the lead's planner events, newest first. */
    public function index(Lead $lead): AnonymousResourceCollection
    {
        return CallResource::collection(
            $lead->calls()->with('agent')->get(),
        );
    }

    /**
     * Ad-hoc "Start Call" from the lead (the Calling Workspace widget). Planner-first: the service
     * creates the owning `call` PlannerEvent, then opens the Call against it.
     */
    public function storeForLead(Request $request, Lead $lead): JsonResponse
    {
        return $this->created($this->calls->startAdHoc($lead, $request->user()));
    }

    /** Start a call against an EXISTING planned call event (the Planner-driven flow). */
    public function storeForEvent(Request $request, PlannerEvent $plannerEvent): JsonResponse
    {
        return $this->created($this->calls->startForEvent($plannerEvent, $request->user()));
    }

    /** Conclude a call with a disposition (connected completes the planner event; others keep it open). */
    public function complete(CompleteCallRequest $request, Call $call): JsonResponse
    {
        $updated = $this->calls->complete(
            $call,
            CallOutcome::from((string) $request->validated('outcome')),
            $request->validated('notes'),
            $request->user(),
        );

        return (new CallResource($updated->load('agent')))->response();
    }

    /** Discard an in-progress call (tracked as `cancelled`; the planner event stays open for a retry). */
    public function discard(Request $request, Call $call): JsonResponse
    {
        $updated = $this->calls->discard($call, $request->user());

        return (new CallResource($updated->load('agent')))->response();
    }

    /** 201 with the freshly opened call. */
    private function created(Call $call): JsonResponse
    {
        return (new CallResource($call->load('agent')))->response()->setStatusCode(201);
    }
}
