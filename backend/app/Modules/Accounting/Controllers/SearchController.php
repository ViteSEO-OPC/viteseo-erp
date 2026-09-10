<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $service) {}

    /**
     * GET /api/v1/accounting/search?q= — global search across invoices, bills,
     * contacts and the chart of accounts.
     *
     * Deliberately NOT wrapped in a `feature:` gate beyond the module itself:
     * per-group entitlement and permission checks live in the service, so an
     * org without invoicing loses the Invoices group rather than the endpoint.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->search($request->user(), (string) $request->query('q', '')),
        ]);
    }
}
