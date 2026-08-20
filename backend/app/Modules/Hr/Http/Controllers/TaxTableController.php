<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\TaxVersion;
use App\Modules\Hr\Services\TaxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The withholding-tax table, readable. */
class TaxTableController extends Controller
{
    /**
     * GET /api/v1/hr/tax-table?as_of=YYYY-MM-DD — the bands in force on a date, plus every version
     * so the UI can say which table applied when.
     */
    public function show(Request $request, TaxService $tax): JsonResponse
    {
        $data = $request->validate(['as_of' => ['sometimes', 'date']]);
        $asOf = $data['as_of'] ?? now(config('app.timezone_local'))->toDateString();

        $bands = $tax->bandsAsOf($asOf);

        // Which version answered, so the viewer can tell "in force since 2023-01-01" from a fallback
        // to the earliest table (what `bandsAsOf()` does for a date before any version exists).
        $inForce = TaxVersion::whereDate('effective_from', '<=', $asOf)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'data' => [
                'as_of' => $asOf,
                'effective_from' => $inForce?->effective_from?->format('Y-m-d'),
                'note' => $inForce?->note,
                // True when the date precedes every version and the earliest table was used instead
                // of refusing — worth stating rather than presenting a guess as the answer.
                'is_fallback' => $inForce === null && $bands !== [],
                'bands' => array_map(fn (array $b, int $i) => [
                    'min_annual' => $b['min'],
                    // The next band's floor − 0.01, or null for the open-ended top band. Derived here
                    // because the table stores only floors, and a viewer needs ranges to be checkable
                    // against the BIR schedule, which prints them as ranges.
                    'max_annual' => isset($bands[$i + 1]) ? round($bands[$i + 1]['min'] - 0.01, 2) : null,
                    'base_tax' => $b['base'],
                    'rate_on_excess' => $b['rate'],
                ], $bands, array_keys($bands)),
                'versions' => TaxVersion::orderBy('effective_from')->get()->map(fn (TaxVersion $v) => [
                    'effective_from' => $v->effective_from?->format('Y-m-d'),
                    'note' => $v->note,
                ])->all(),
            ],
        ]);
    }
}
