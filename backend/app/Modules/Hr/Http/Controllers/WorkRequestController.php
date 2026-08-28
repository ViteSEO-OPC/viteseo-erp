<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\CancelOwnRequestRequest;
use App\Modules\Hr\Http\Requests\ReopenRequest;
use App\Modules\Hr\Http\Requests\StoreWorkRequestRequest;
use App\Modules\Hr\Http\Resources\WorkRequestResource;
use App\Modules\Hr\Models\WorkRequest;
use App\Modules\Hr\Services\WorkRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WorkRequestController extends Controller
{
    public function __construct(private readonly WorkRequestService $service) {}

    // GET /api/v1/hr/work-requests — HR sees all; employees see their own.
    public function index(Request $request): JsonResponse
    {
        // Applied by the DATABASE. In the browser they would only narrow the page on screen.
        return WorkRequestResource::collection(
            $this->service->list($request->user(), $request->only(['search', 'status', 'per_page'])),
        )->response();
    }

    /** POST /api/v1/hr/work-requests — the caller asks to work today. */
    public function store(StoreWorkRequestRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(
                ['message' => 'Only employees can file a work request.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $today = now(config('app.timezone_local'))->toDateString();

        return WorkRequestResource::make(
            $this->service->create($employee, $today, $request->validated('reason')),
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // POST /api/v1/hr/work-requests/{workRequest}/approve — HR only. Writes the attendance.
    public function approve(Request $request, WorkRequest $workRequest): JsonResponse
    {
        return WorkRequestResource::make(
            $this->service->approve($workRequest, $request->user()),
        )->response();
    }

    // POST /api/v1/hr/work-requests/{workRequest}/reject — HR only.
    public function reject(Request $request, WorkRequest $workRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return WorkRequestResource::make(
            $this->service->reject($workRequest, $request->user(), $data['reason']),
        )->response();
    }

    // POST /api/v1/hr/work-requests/{workRequest}/reopen — HR only: undo the decision.
    public function reopen(ReopenRequest $request, WorkRequest $workRequest): JsonResponse
    {
        return WorkRequestResource::make(
            $this->service->reopen($workRequest, $request->validated('reason')),
        )->response();
    }

    // POST /api/v1/hr/work-requests/{workRequest}/cancel — the employee withdraws their own.
    public function cancel(CancelOwnRequestRequest $request, WorkRequest $workRequest): JsonResponse
    {
        return WorkRequestResource::make(
            $this->service->cancel($workRequest, $request->user(), $request->validated()['reason']),
        )->response();
    }
}
