<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $service) {}

    // GET /api/v1/hr/me/notifications?limit=20&offset=0 — bell items + per-nav badge counts.
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 20);
        $offset = (int) $request->query('offset', 0);

        return response()->json(['data' => $this->service->forUser($request->user(), $limit, $offset)]);
    }
}
