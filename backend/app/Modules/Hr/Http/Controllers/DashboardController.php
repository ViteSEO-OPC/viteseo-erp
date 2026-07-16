<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $service) {}

    // GET /api/v1/hr/dashboard/stats — HR home aggregates (hr_officer only).
    public function hrStats(): JsonResponse
    {
        return response()->json(['data' => $this->service->hrStats()]);
    }
}
