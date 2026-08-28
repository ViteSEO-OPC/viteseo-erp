<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Services\ApprovalQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The HR dashboard's approvals card: thirteen queues, one request. */
class ApprovalQueueController extends Controller
{
    public function __construct(private readonly ApprovalQueueService $service) {}

    /**
     * GET /api/v1/hr/approvals/pending — everything awaiting a decision, oldest first.
     *
     * Replaces thirteen browser-side reads that were merged and sorted in JavaScript. The card
     * shows a fixed number of rows, so the limit is what it renders, and `total` states how many
     * are outstanding altogether.
     */
    public function index(Request $request): JsonResponse
    {
        // Bounded both ways: this is a to-do card, not a report.
        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        $queue = $this->service->pending($limit);

        return response()->json([
            'data' => $queue['items'],
            'meta' => ['total' => $queue['total'], 'limit' => $limit],
        ]);
    }
}
