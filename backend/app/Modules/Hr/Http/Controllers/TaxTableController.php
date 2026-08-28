<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\SaveTaxTableRequest;
use App\Modules\Hr\Models\TaxVersion;
use App\Modules\Hr\Services\TaxService;
use App\Modules\Hr\Services\TaxVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The withholding-tax table: readable by payroll, editable by HR. */
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
                    // The next band's floor, or null for the open-ended top band. Derived here
                    // because the table stores only floors, and a viewer needs ranges to be checkable
                    // against the BIR schedule, which prints them as ranges.
                    //
                    // The ceiling is the next floor EXACTLY, not one centavo below it. The bands
                    // partition as [0, 250000] then (250000, 400000] — ₱250,000 itself is exempt,
                    // because annualTax() takes the last band whose floor the income exceeds and
                    // 250,000 does not exceed 250,000. Subtracting a centavo here printed the exempt
                    // tier as "up to ₱249,999.99", inventing a gap the arithmetic does not have and
                    // contradicting the BIR's own "Not over ₱250,000". The viewer states the bounds
                    // as over/not-over, which is how the schedule prints them and leaves no overlap.
                    'max_annual' => $bands[$i + 1]['min'] ?? null,
                    'base_tax' => $b['base'],
                    'rate_on_excess' => $b['rate'],
                ], $bands, array_keys($bands)),
                'versions' => TaxVersion::orderBy('effective_from')->get()->map(fn (TaxVersion $v) => [
                    'id' => $v->id,
                    'effective_from' => $v->effective_from?->format('Y-m-d'),
                    'note' => $v->note,
                ])->all(),
                // The editor publishes for today, always. Both of these tell it what that means
                // right now: which date it would write, and whether doing so replaces a table
                // published earlier today rather than adding one.
                'today' => $today = now(config('app.timezone_local'))->toDateString(),
                'has_version_today' => TaxVersion::whereDate('effective_from', $today)->exists(),
            ],
        ]);
    }

    /**
     * PUT /api/v1/hr/tax-table — publish the bands HR typed, in force from today.
     *
     * There is no date to choose: a rate takes effect forward, and anything retroactive goes
     * through a payslip correction. Saving twice in a day replaces the day's table.
     */
    public function update(SaveTaxTableRequest $request, TaxVersionService $versions): JsonResponse
    {
        $version = $versions->save($request->validated()['bands'], $request->validated()['note'] ?? null);

        return response()->json(['data' => [
            'effective_from' => $version->effective_from?->format('Y-m-d'),
            'note' => $version->note,
            'bands' => $version->brackets->map(fn ($b) => [
                'min_annual' => (float) $b->min_annual,
                'base_tax' => (float) $b->base_tax,
                'rate_on_excess' => (float) $b->rate_on_excess,
            ])->values()->all(),
        ]]);
    }

    // DELETE /api/v1/hr/tax-versions/{taxVersion} — undo a table published in error. The last
    // remaining table cannot be removed; see TaxVersionService::delete().
    public function destroyVersion(TaxVersion $taxVersion, TaxVersionService $versions): JsonResponse
    {
        $versions->delete($taxVersion);

        return response()->json(['data' => ['deleted' => true]]);
    }
}
