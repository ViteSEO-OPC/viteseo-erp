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
     * the token.
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
            $this->service->list(
                $employee->id,
                $validated['month'] ?? null,
                $request->only(['per_page']),
            ),
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

        // Item 11 — READ ONLY. This used to rebuild the requested month, and with no month
        // rebuild EVERY month since the org started tracking, before serving a page of flags.
        // Opening "All time" therefore cost a full-history rescan, and every reload paid it
        // again. The flags are maintained by the writes that change them and by the nightly
        // sweep; `POST /hr/violations/recompute` is still there to force one by hand.
        return ViolationResource::collection(
            $this->service->list(
                $employeeId ? (int) $employeeId : null,
                $month,
                $request->only(['per_page']),
            ),
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

    // POST /hr/violations/{violation}/reopen — undo a resolve, re-arming the flag.
    public function reopen(Violation $violation): JsonResponse
    {
        return ViolationResource::make($this->service->unresolve($violation))->response();
    }
}
