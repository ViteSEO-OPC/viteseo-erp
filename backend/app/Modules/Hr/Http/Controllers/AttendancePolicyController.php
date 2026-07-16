<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\UpdateAttendancePolicyRequest;
use App\Modules\Hr\Http\Resources\AttendancePolicyResource;
use App\Modules\Hr\Services\AttendancePolicyService;
use Illuminate\Http\JsonResponse;

// Company → Rules (attendance policy + task format). hr_officer-only.
class AttendancePolicyController extends Controller
{
    public function __construct(private readonly AttendancePolicyService $service) {}

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
}
