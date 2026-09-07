<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\AlertsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Feeds Accounting's notification bell. Intentionally NOT gated by
 * `feature:accounting.invoicing` / `feature:accounting.bills` as a whole — an
 * org that only availed bills must still get an answer — so the entitlement
 * check happens per-section inside {@see AlertsService}.
 */
class AlertsController extends Controller
{
    public function __construct(
        private readonly AlertsService $alerts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Same read permission the invoice and bill lists use.
        abort_unless($request->user()->can('accounting.journal.view'), 403);

        return response()->json(['data' => $this->alerts->forCurrentOrg()]);
    }
}
