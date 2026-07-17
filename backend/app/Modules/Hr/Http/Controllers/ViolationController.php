<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Resources\ViolationResource;
use App\Modules\Hr\Models\Violation;
use App\Modules\Hr\Services\ViolationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Attendance → violations. hr_officer-only, except me() which serves the caller
// their own flags.
class ViolationController extends Controller
{
    public function __construct(private readonly ViolationService $service) {}

    /**
     * GET /hr/me/violations?month=YYYY-MM — the caller's own flags, resolved from
     * the token. Read-only on purpose: unlike index(), it never triggers a
     * recompute, so an employee opening their dashboard can't kick off a
     * full-history rebuild.
     */
    public function me(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $employee = $request->user()->employee;

        if (! $employee) {
            return ViolationResource::collection([])->response();
        }

        return ViolationResource::collection(
            $this->service->list($employee->id, $validated['month'] ?? null),
        )->response();
    }

    // GET /hr/violations?employee=&month=YYYY-MM
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee' => ['nullable', 'integer'],
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $employeeId = $validated['employee'] ?? null;
        $month = $validated['month'] ?? null;

        // A specific month → (re)build just that month. No month → build the full
        // history so the "All time" view has every month's data, not only opened ones.
        if ($month) {
            $this->service->recompute($month);
        } else {
            $this->service->recomputeHistory();
        }

        return ViolationResource::collection(
            $this->service->list($employeeId ? (int) $employeeId : null, $month),
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
