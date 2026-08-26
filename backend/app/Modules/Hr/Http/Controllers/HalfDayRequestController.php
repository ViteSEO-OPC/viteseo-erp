<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\ReopenRequest;
use App\Modules\Hr\Http\Requests\StoreHalfDayRequestRequest;
use App\Modules\Hr\Http\Resources\HalfDayRequestResource;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Services\HalfDayRequestService;
use App\Modules\Hr\Http\Requests\CancelOwnRequestRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HalfDayRequestController extends Controller
{
    public function __construct(private readonly HalfDayRequestService $service) {}

    // GET /api/v1/hr/half-day-requests — HR sees all; employees see their own.
    public function index(Request $request): JsonResponse
    {
        // Applied by the DATABASE. In the browser they would only narrow the page on screen.
        return HalfDayRequestResource::collection(
            $this->service->list($request->user(), $request->only(['search', 'status', 'per_page'])),
        )->response();
    }

    // POST /api/v1/hr/half-day-requests — the caller files their own half-day.
    public function store(StoreHalfDayRequestRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(
                ['message' => 'Only employees can file a half-day.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        return HalfDayRequestResource::make($this->service->create($employee, $request->validated()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // POST /api/v1/hr/half-day-requests/{halfDayRequest}/approve — HR only.
    public function approve(Request $request, HalfDayRequest $halfDayRequest): JsonResponse
    {
        return HalfDayRequestResource::make($this->service->approve($halfDayRequest, $request->user()))->response();
    }

    // POST /api/v1/hr/half-day-requests/{halfDayRequest}/reject — HR only.
    public function reject(Request $request, HalfDayRequest $halfDayRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return HalfDayRequestResource::make(
            $this->service->reject($halfDayRequest, $request->user(), $data['reason']),
        )->response();
    }

    // POST /api/v1/hr/half-day-requests/{halfDayRequest}/reopen — HR only: undo the decision.
    public function reopen(ReopenRequest $request, HalfDayRequest $halfDayRequest): JsonResponse
    {
        return HalfDayRequestResource::make(
            $this->service->reopen($halfDayRequest, $request->validated('reason')),
        )->response();
    }

    // POST /hr/half-day-requests/{halfDayRequest}/cancel — the employee withdraws their own pending request.
    public function cancel(CancelOwnRequestRequest $request, HalfDayRequest $halfDayRequest): JsonResponse
    {
        return HalfDayRequestResource::make(
            $this->service->cancel($halfDayRequest, $request->user(), $request->validated()['reason']),
        )->response();
    }
}
