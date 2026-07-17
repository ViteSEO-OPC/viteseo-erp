<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Models\User;
use App\Modules\Crm\Services\Outreach\DeliveryMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RC3 Phase 4 — read-only delivery-lifecycle monitoring endpoint. Aggregates the existing `email_events`
 * + `reminder_deliveries` timelines (+ live `emails.status`). Ownership-scoped in the service. No writes,
 * no engagement analytics, no AI.
 */
class DeliveryMonitorController extends Controller
{
    public function show(Request $request, DeliveryMonitorService $monitor): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return response()->json(['data' => $monitor->summary($viewer)]);
    }
}
