<?php

declare(strict_types=1);

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Services\Calling\CallingOverviewService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/admin/calling — how calling is configured across every organization.
 *
 * The platform operator's read-only answer to "is this client's calling set up, and if not, what is
 * missing?". Zerem has no organization switcher and the Calling settings endpoints resolve the
 * organization from the signed-in user's own membership, so before this existed that question could
 * only be answered from a database session.
 *
 * SUPER ADMIN ONLY, enforced by `role:super_admin` on the route group it sits in — the same gate as the
 * rest of `/admin/*`. Not `crm_admin`: a client's admin sees their own organization's Calling card, and
 * nothing about another tenant.
 *
 * READ-ONLY BY DESIGN, not by omission. There is no cross-organization write here and none is planned:
 * a client's Twilio account is their property and their bill, and being able to SEE a configuration is
 * not a reason to be able to change it. Editing stays where it belongs — that organization's own admin,
 * on its own settings screen.
 *
 * THIN. Every decision lives in {@see CallingOverviewService}, which also states why no credential and
 * no identifier is in this payload.
 */
class CallingOverviewController extends Controller
{
    public function __construct(private readonly CallingOverviewService $overview) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                // The deployment-level answer first: when per-organization mode is misconfigured, every
                // per-organization row below is a consequence of it rather than a fault of its own.
                'mode' => $this->overview->mode(),
                'organizations' => $this->overview->organizations(),
            ],
        ]);
    }
}
