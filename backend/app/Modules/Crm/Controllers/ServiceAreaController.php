<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Requests\StoreServiceAreaRequest;
use App\Modules\Crm\Resources\ServiceAreaResource;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\ServiceArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Service-Area Registry management (Nearby Clients — Target Service Areas). The PO/admins own the
 * list of administrative areas that count as a **target service location** (frozen Target Location
 * +10). This is business CONFIGURATION — the controller stores/removes areas and reports how many
 * leads currently fall in a target area (via `Lead::scopeInTargetLocation`, server-side). It never
 * computes or applies the Lead Score. Route-guarded to super-admin|admin.
 */
class ServiceAreaController extends Controller
{
    /** List target areas (newest first) + a summary of how many leads currently fall in one. */
    public function index(): AnonymousResourceCollection
    {
        $areas = ServiceArea::query()->latest('id')->get();

        // Data-only determination (NOT a score): leads whose admin area matches an active target
        // area, out of all leads. Supplies context for the Nearby Clients page.
        $inTarget = Lead::query()->inTargetLocation()->count();
        $total = Lead::query()->count();

        return ServiceAreaResource::collection($areas)->additional([
            'meta' => [
                'leadsInTargetLocation' => $inTarget,
                'totalLeads' => $total,
            ],
        ]);
    }

    /** Add a target service area to the registry. */
    public function store(StoreServiceAreaRequest $request): JsonResponse
    {
        $area = ServiceArea::create([
            'name' => $request->string('name')->trim()->value(),
            'level' => $request->string('level')->value(),
            'active' => true,
        ]);

        return (new ServiceAreaResource($area))->response()->setStatusCode(201);
    }

    /** Remove a target service area (soft delete). */
    public function destroy(ServiceArea $serviceArea): JsonResponse
    {
        $serviceArea->delete();

        return response()->json(status: 204);
    }
}
