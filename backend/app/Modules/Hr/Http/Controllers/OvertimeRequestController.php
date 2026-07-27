<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreOvertimeRequestRequest;
use App\Modules\Hr\Http\Resources\OvertimeRequestResource;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Services\OvertimeRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OvertimeRequestController extends Controller
{
    public function __construct(private readonly OvertimeRequestService $service) {}

    // GET /api/v1/hr/overtime-requests — HR sees all; employees see their own.
    public function index(Request $request): JsonResponse
    {
        return OvertimeRequestResource::collection($this->service->list($request->user()))->response();
    }

    // POST /api/v1/hr/overtime-requests — the caller files their own overtime request.
    public function store(StoreOvertimeRequestRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(
                ['message' => 'Only employees can file an overtime request.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        return OvertimeRequestResource::make($this->service->create($employee, $request->validated()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // POST /api/v1/hr/overtime-requests/{overtimeRequest}/approve — HR only.
    public function approve(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        return OvertimeRequestResource::make($this->service->approve($overtimeRequest, $request->user()))->response();
    }

    // POST /api/v1/hr/overtime-requests/{overtimeRequest}/reject — HR only.
    public function reject(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return OvertimeRequestResource::make(
            $this->service->reject($overtimeRequest, $request->user(), $data['reason']),
        )->response();
    }
}
