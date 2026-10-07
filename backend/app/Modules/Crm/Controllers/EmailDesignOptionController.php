<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\EmailDesign;
use Illuminate\Http\JsonResponse;

/**
 * The Email Designs a Lead Details reply may be sent in — read-only, for the people who send replies.
 *
 * Settings → Email Designs stays the one place designs are created, edited and deleted (super_admin|crm_admin).
 * This lists only what a reply's design picker needs — an id and a name — never a layout, and offers no way
 * to change anything. BelongsToOrg and SoftDeletes scope it to this organization's live designs.
 */
class EmailDesignOptionController extends Controller
{
    public function index(): JsonResponse
    {
        $designs = EmailDesign::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'is_default']);

        return response()->json(['data' => $designs->map(fn (EmailDesign $design): array => [
            'id' => (string) $design->id,
            'name' => $design->name,
            'isDefault' => $design->is_default,
        ])->values()]);
    }
}
