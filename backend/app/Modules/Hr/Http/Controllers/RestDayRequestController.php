<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\CancelOwnRequestRequest;
use App\Modules\Hr\Http\Requests\ReopenRequest;
use App\Modules\Hr\Http\Requests\StoreRestDayRequestRequest;
use App\Modules\Hr\Http\Resources\RestDayRequestResource;
use App\Modules\Hr\Models\RestDayRequest;
use App\Modules\Hr\Services\RestDayRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RestDayRequestController extends Controller
{
    public function __construct(private readonly RestDayRequestService $service) {}

    // GET /api/v1/hr/rest-day-requests — HR sees all; employees see their own.
    public function index(Request $request): JsonResponse
    {
        return RestDayRequestResource::collection($this->service->list($request->user()))->response();
    }

    /**
     * POST /api/v1/hr/rest-day-requests — the caller asks to work today's rest day.
     *
     * The date is the caller's Manila today, taken from the server clock: this stands in for a
     * time-in punch, so it can only ever be about right now.
     */
    public function store(StoreRestDayRequestRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(
                ['message' => 'Only employees can file a rest-day request.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $today = now(config('app.timezone_local'))->toDateString();

        return RestDayRequestResource::make(
            $this->service->create($employee, $today, $request->validated('reason')),
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // POST /api/v1/hr/rest-day-requests/{restDayRequest}/approve — HR only. Writes the attendance.
    public function approve(Request $request, RestDayRequest $restDayRequest): JsonResponse
    {
        return RestDayRequestResource::make(
            $this->service->approve($restDayRequest, $request->user()),
        )->response();
    }

    // POST /api/v1/hr/rest-day-requests/{restDayRequest}/reject — HR only.
    public function reject(Request $request, RestDayRequest $restDayRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return RestDayRequestResource::make(
            $this->service->reject($restDayRequest, $request->user(), $data['reason']),
        )->response();
    }

    // POST /api/v1/hr/rest-day-requests/{restDayRequest}/reopen — HR only: undo the decision.
    public function reopen(ReopenRequest $request, RestDayRequest $restDayRequest): JsonResponse
    {
        return RestDayRequestResource::make(
            $this->service->reopen($restDayRequest, $request->validated('reason')),
        )->response();
    }

    // POST /api/v1/hr/rest-day-requests/{restDayRequest}/cancel — the employee withdraws their own.
    public function cancel(CancelOwnRequestRequest $request, RestDayRequest $restDayRequest): JsonResponse
    {
        return RestDayRequestResource::make(
            $this->service->cancel($restDayRequest, $request->user(), $request->validated()['reason']),
        )->response();
    }
}
