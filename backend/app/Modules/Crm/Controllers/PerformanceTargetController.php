<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\PerformanceTarget;
use App\Modules\Crm\Requests\StorePerformanceTargetRequest;
use App\Modules\Crm\Requests\UpdatePerformanceTargetRequest;
use App\Modules\Crm\Resources\PerformanceTargetResource;
use App\Modules\Crm\Services\Performance\PerformanceTargetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Goal Management CRUD — Reports & Performance → Manage Performance.
 *
 * Every route is behind `crm.performance.manage`, held by Super Admin and Admin (approved PO
 * decision); a BD reads their own performance and configures nothing. It stays a permission separate
 * from `crm.business_rules.manage` on purpose: they are different capabilities, so the two can be
 * narrowed independently later.
 *
 * Thin: identity/lifecycle rules live in PerformanceTargetService, validation in the form requests,
 * and the activity trail in PerformanceTargetObserver.
 */
class PerformanceTargetController extends Controller
{
    public function __construct(private readonly PerformanceTargetService $targets) {}

    /** Optional `?user_id=` narrows to one BD; otherwise every configured target. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $targets = PerformanceTarget::query()
            ->with('user:id,name')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->orderByDesc('period_start')
            ->orderBy('metric')
            ->paginate(50);

        return PerformanceTargetResource::collection($targets);
    }

    public function store(StorePerformanceTargetRequest $request): JsonResponse
    {
        $target = $this->targets->create($request->validated(), $request->user()?->id);

        return response()->json([
            'data' => new PerformanceTargetResource($target->load('user:id,name')),
        ], 201);
    }

    public function update(UpdatePerformanceTargetRequest $request, PerformanceTarget $performanceTarget): JsonResponse
    {
        $updated = $this->targets->update($performanceTarget, $request->validated());

        return response()->json([
            'data' => new PerformanceTargetResource($updated->load('user:id,name')),
        ]);
    }

    public function destroy(PerformanceTarget $performanceTarget): JsonResponse
    {
        $this->targets->delete($performanceTarget);

        return response()->json(['data' => ['status' => 'deleted']]);
    }
}
