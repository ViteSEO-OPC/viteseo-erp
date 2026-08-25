<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\ReopenRequest;
use App\Modules\Hr\Http\Requests\StorePayslipRequestRequest;
use App\Modules\Hr\Http\Resources\PayslipRequestResource;
use App\Modules\Hr\Models\PayslipRequest;
use App\Modules\Hr\Services\PayslipRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayslipRequestController extends Controller
{
    public function __construct(private readonly PayslipRequestService $service) {}

    // GET /hr/payslip-requests — HR queue. GET /hr/me/payslip-requests — own.
    public function index(Request $request): JsonResponse
    {
        // Applied by the DATABASE. In the browser they would only narrow the page on screen.
        return PayslipRequestResource::collection(
            $this->service->list($request->user(), $request->only(['search', 'status', 'per_page'])),
        )->response();
    }

    // POST /hr/me/payslip-requests — the caller requests a payslip copy.
    public function store(StorePayslipRequestRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['message' => 'Only employees can request payslips.'], Response::HTTP_FORBIDDEN);
        }

        return PayslipRequestResource::make($this->service->create($employee, $request->validated()))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // POST /hr/payslip-requests/{payslipRequest}/approve — HR only.
    public function approve(Request $request, PayslipRequest $payslipRequest): JsonResponse
    {
        return PayslipRequestResource::make($this->service->approve($payslipRequest, $request->user()))->response();
    }

    // POST /hr/payslip-requests/{payslipRequest}/reject — HR only.
    public function reject(Request $request, PayslipRequest $payslipRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return PayslipRequestResource::make(
            $this->service->reject($payslipRequest, $request->user(), $data['reason'] ?? null),
        )->response();
    }

    // POST /hr/payslip-requests/{payslipRequest}/reopen — HR only: undo the decision.
    public function reopen(ReopenRequest $request, PayslipRequest $payslipRequest): JsonResponse
    {
        return PayslipRequestResource::make(
            $this->service->reopen($payslipRequest, $request->validated('reason')),
        )->response();
    }
}
