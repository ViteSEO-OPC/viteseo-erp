<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Resources\ViolationResource;
use App\Modules\Hr\Models\Violation;
use App\Modules\Hr\Services\ViolationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Attendance → violations. hr_officer-only.
class ViolationController extends Controller
{
    public function __construct(private readonly ViolationService $service) {}

    // GET /hr/violations?employee=
    public function index(Request $request): JsonResponse
    {
        $employeeId = $request->query('employee');

        return ViolationResource::collection(
            $this->service->list($employeeId ? (int) $employeeId : null),
        )->response();
    }

    // POST /hr/violations/recompute
    public function recompute(): JsonResponse
    {
        $this->service->recompute();

        return ViolationResource::collection($this->service->list())->response();
    }

    // POST /hr/violations/{violation}/resolve
    public function resolve(Violation $violation): JsonResponse
    {
        return ViolationResource::make($this->service->resolve($violation))->response();
    }
}
