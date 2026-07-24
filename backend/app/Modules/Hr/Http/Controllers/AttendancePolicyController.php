<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\UpdateAttendancePolicyRequest;
use App\Modules\Hr\Http\Resources\AttendancePolicyResource;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Http\JsonResponse;

// Company → Rules (attendance policy + task format). hr_officer-only.
class AttendancePolicyController extends Controller
{
    public function __construct(
        private readonly AttendancePolicyService $service,
        private readonly PayrollService $payroll,
    ) {}

    // GET /hr/attendance-policy
    public function show(): JsonResponse
    {
        return AttendancePolicyResource::make($this->service->get())->response();
    }

    // PUT/PATCH /hr/attendance-policy
    public function update(UpdateAttendancePolicyRequest $request): JsonResponse
    {
        return AttendancePolicyResource::make($this->service->update($request->validated()))->response();
    }

    // POST /hr/attendance-policy/preview — dry-run (step 6b): what the PROPOSED policy
    // would have deducted last period, without saving anything. Same validation as update.
    public function preview(UpdateAttendancePolicyRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->payroll->previewDeductions($request->validated())]);
    }
}
