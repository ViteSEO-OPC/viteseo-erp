<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** The most notifications one request may ask for. The bell shows far fewer. */
    private const MAX_LIMIT = 100;

    public function __construct(private readonly NotificationService $service) {}

    // GET /api/v1/hr/me/notifications?limit=20&offset=0 — bell items + per-nav badge counts.
    public function index(Request $request): JsonResponse
    {
        // Clamped, because both numbers come straight off the query string: `?limit=999999`
        // would hand back the entire notification history in one response, and a zero or
        // negative window is a request the caller cannot have meant.
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query('limit', 20)));
        $offset = max(0, (int) $request->query('offset', 0));

        return response()->json(['data' => $this->service->forUser($request->user(), $limit, $offset)]);
    }
}
