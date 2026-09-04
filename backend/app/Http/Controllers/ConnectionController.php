<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ConnectionRegistry;
use App\Support\Connections;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Cross-module connections ("Integrations"): read the current org's connection
 * matrix, and (for an org admin) toggle an available one on/off. Availability is
 * derived from entitlements + ConnectionRegistry; this only writes the override.
 */
class ConnectionController extends Controller
{
    /** GET /api/v1/connections — the current org's decorated connection list. */
    public function index(): JsonResponse
    {
        $orgId = CurrentOrg::id() ?? 0;

        return response()->json(['data' => Connections::forOrg($orgId)]);
    }

    /** PUT /api/v1/connections/{key} — turn an available connection on/off (org admin). */
    public function update(Request $request, string $key): JsonResponse
    {
        // Same org-admin gate the Team / Custom Roles admin actions use.
        abort_unless(
            $request->user()->hasRole('acc_admin') || $request->user()->hasRole('super_admin'),
            403,
        );

        if (! ConnectionRegistry::exists($key)) {
            abort(404, 'Unknown connection.');
        }

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $orgId = CurrentOrg::id() ?? 0;

        if (! Connections::available($orgId, $key)) {
            throw ValidationException::withMessages([
                'enabled' => 'This connection is not available — avail both systems first.',
            ]);
        }

        Connections::setEnabled($orgId, $key, (bool) $data['enabled']);

        $entry = collect(Connections::forOrg($orgId))->firstWhere('key', $key);

        return response()->json(['data' => $entry]);
    }
}
