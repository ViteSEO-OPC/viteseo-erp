<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\UpsertDailyOverrideRequest;
use App\Modules\Hr\Http\Resources\DailyOverrideResource;
use App\Modules\Hr\Services\DailyOverrideService;
use Illuminate\Http\JsonResponse;

class DailyOverrideController extends Controller
{
    public function __construct(private readonly DailyOverrideService $service) {}

    /** GET /api/v1/hr/daily-overrides?date=YYYY-MM-DD */
    public function show(UpsertDailyOverrideRequest $_dummy): JsonResponse
    {
        $date = request()->query('date');
        $override = $this->service->get($date);
        return response()->json(['data' => $override ? DailyOverrideResource::make($override) : null]);
    }

    /** PUT /api/v1/hr/daily-overrides */
    public function upsert(UpsertDailyOverrideRequest $request): JsonResponse
    {
        $override = $this->service->upsert(
            $request->validated('date'),
            $request->validated('grace_minutes'),
        );
        return response()->json(['data' => DailyOverrideResource::make($override)]);
    }
}
