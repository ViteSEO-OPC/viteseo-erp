<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreEarlyOutRequestRequest;
use App\Modules\Hr\Http\Resources\EarlyOutRequestResource;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Services\EarlyOutRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EarlyOutRequestController extends Controller
{
    public function __construct(private readonly EarlyOutRequestService $service) {}

    // GET /api/v1/hr/early-out-requests — HR sees all; employees see their own.
    public function index(Request $request): JsonResponse
    {
        return EarlyOutRequestResource::collection($this->service->list($request->user()))->response();
    }

    // POST /api/v1/hr/early-out-requests — the caller files their own early-out.
    public function store(StoreEarlyOutRequestRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(
                ['message' => 'Only employees can file an early-out.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        return EarlyOutRequestResource::make($this->service->create($employee, $request->validated()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // POST /api/v1/hr/early-out-requests/{earlyOutRequest}/approve — HR only.
    public function approve(Request $request, EarlyOutRequest $earlyOutRequest): JsonResponse
    {
        return EarlyOutRequestResource::make($this->service->approve($earlyOutRequest, $request->user()))->response();
    }

    // POST /api/v1/hr/early-out-requests/{earlyOutRequest}/reject — HR only.
    public function reject(Request $request, EarlyOutRequest $earlyOutRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return EarlyOutRequestResource::make(
            $this->service->reject($earlyOutRequest, $request->user(), $data['reason']),
        )->response();
    }
}
