<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\ReopenRequest;
use App\Modules\Hr\Http\Requests\StoreConcernRequest;
use App\Modules\Hr\Http\Requests\StoreConcernRevealRequest;
use App\Modules\Hr\Http\Resources\ConcernResource;
use App\Modules\Hr\Http\Resources\ConcernRevealRequestResource;
use App\Modules\Hr\Http\Resources\MeetingResource;
use App\Modules\Hr\Models\Concern;
use App\Modules\Hr\Models\ConcernRevealRequest;
use App\Modules\Hr\Services\ConcernRevealService;
use App\Modules\Hr\Services\ConcernService;
use App\Modules\Hr\Http\Requests\CancelOwnRequestRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConcernController extends Controller
{
    public function __construct(
        private readonly ConcernService $service,
        private readonly ConcernRevealService $reveal,
    ) {}

    // GET /hr/concerns — HR/Admin queue (identity hidden for anonymous).
    public function index(Request $request): JsonResponse
    {
        // Applied by the DATABASE. The search deliberately does not match the reporter's name
        // on an anonymous concern — see ConcernService::concernSearch().
        return ConcernResource::collection(
            $this->service->listForHr($request->only(['search', 'status', 'per_page'])),
        )->response();
    }

    // GET /hr/me/concerns — the caller's own concerns.
    public function meIndex(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => []]);
        }

        return ConcernResource::collection(
            $this->service->listForEmployee($employee, $request->only(['search', 'status', 'per_page'])),
        )->response();
    }

    // POST /hr/me/concerns — submit a concern (named or anonymous).
    public function store(StoreConcernRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['message' => 'Only employees can submit concerns.'], Response::HTTP_FORBIDDEN);
        }

        $concern = $this->service->create(
            $employee,
            $request->validated(),
            $request->file('attachments', []),
        );

        return ConcernResource::make($concern)
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // POST /hr/concerns/{concern}/status — under_review | resolved (HR only).
    public function status(Request $request, Concern $concern): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:under_review,resolved']]);

        return ConcernResource::make($this->service->setStatus($concern, $data['status'], $request->user()))->response();
    }

    // POST /hr/concerns/{concern}/reopen — HR only: step the status back
    // (resolved → under review, under review → pending).
    public function reopen(ReopenRequest $request, Concern $concern): JsonResponse
    {
        return ConcernResource::make(
            $this->service->reopen($concern, $request->validated('reason')),
        )->response();
    }

    // POST /hr/concerns/{concern}/meetings — schedule a meeting (HR only).
    public function scheduleMeeting(Request $request, Concern $concern): JsonResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'agenda' => ['nullable', 'string', 'max:1000'],
        ]);

        return MeetingResource::make($this->service->scheduleMeeting($concern, $data, $request->user()))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * POST /hr/concerns/{concern}/reveal-requests — HR asks who filed this (HR only).
     *
     * Asking is all this does. The identity stays hidden until the reporter says yes, and the
     * ask itself is recorded whether they do or not.
     */
    public function requestReveal(StoreConcernRevealRequest $request, Concern $concern): JsonResponse
    {
        return ConcernRevealRequestResource::make(
            $this->reveal->request($concern, $request->user(), $request->validated('reason')),
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * POST /hr/me/concern-reveal-requests/{revealRequest}/accept — the reporter agrees.
     *
     * Not HR-gated, and deliberately so: the whole point is that this decision is not HR's.
     * The service checks the caller IS the reporter.
     */
    public function acceptReveal(Request $request, ConcernRevealRequest $revealRequest): JsonResponse
    {
        return ConcernRevealRequestResource::make(
            $this->reveal->accept($revealRequest, $request->user()),
        )->response();
    }

    // POST /hr/me/concern-reveal-requests/{revealRequest}/decline — the reporter says no, with a reason.
    public function declineReveal(Request $request, ConcernRevealRequest $revealRequest): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Say why you would rather stay anonymous — HR sees this instead of your name.',
        ]);

        return ConcernRevealRequestResource::make(
            $this->reveal->decline($revealRequest, $request->user(), $data['reason']),
        )->response();
    }

    // POST /hr/.../{concern}/cancel — the employee withdraws their own pending concern.
    public function cancel(CancelOwnRequestRequest $request, Concern $concern): JsonResponse
    {
        return ConcernResource::make(
            $this->service->cancel($concern, $request->user(), $request->validated()['reason']),
        )->response();
    }
}
