<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Org;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The current org's business identity — what prints on the invoice letterhead
 * (name / TIN / address) plus its VAT registration and default rate. Readable
 * by any member (the invoice form prefills from it); writable by the Finance
 * Admin only.
 */
class OrgSettingsController extends Controller
{
    /** GET /api/v1/accounting/org-settings */
    public function show(): JsonResponse
    {
        return $this->payload(Org::findOrFail(CurrentOrg::id()));
    }

    /** PUT /api/v1/accounting/org-settings */
    public function update(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasRole('acc_admin') || $request->user()->hasRole('super_admin'),
            403,
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_vat_registered' => ['required', 'boolean'],
            'default_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $org = Org::findOrFail(CurrentOrg::id());
        $org->update($data);

        return $this->payload($org->refresh());
    }

    private function payload(Org $org): JsonResponse
    {
        return response()->json([
            'data' => [
                'name' => $org->name,
                'tin' => $org->tin,
                'address' => $org->address,
                'is_vat_registered' => (bool) $org->is_vat_registered,
                'default_tax_rate' => $org->default_tax_rate !== null ? (float) $org->default_tax_rate : null,
            ],
        ]);
    }
}
