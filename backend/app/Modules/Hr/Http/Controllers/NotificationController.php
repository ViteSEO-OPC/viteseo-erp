<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $service) {}

    // GET /api/v1/hr/me/notifications — bell items + per-nav badge counts.
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->forUser($request->user())]);
    }
}
