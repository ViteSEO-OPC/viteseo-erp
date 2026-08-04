<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\TaxVersion;

/**
 * Annual withholding tax, from the table that was in force for the period being priced (FIN-027).
 *
 * WHAT THIS REPLACES. A `match (true)` literal inside `PayrollService`, holding the TRAIN 2023+
 * brackets. It worked, and the figures were right, but it could not be dated: re-pricing a 2022
 * cycle used the 2023 table, changing a bracket meant a deploy, and HR had no way to see or check
 * the numbers that decided everyone's tax.
 *
 * THE FALLBACK IS DELIBERATE AND IT MATTERS. If no version is on or before the cycle, this uses
 * the EARLIEST table rather than refusing or returning zero. FIN-035 argues the opposite for
 * statutory contributions — block the run rather than deduct ₱0 — and the difference is real:
 * a missing contribution version means a rate nobody has entered, whereas a cycle before the
 * earliest tax table is a cycle from before the company recorded its tax history, and the seeded
 * opening table already reproduces what the hardcoded `match` did for every date. Falling back
 * therefore preserves existing behaviour exactly; refusing would make old cycles un-runnable for
 * a reason that has nothing to do with them.
 *
 * Memoised per resolved date: a payroll run prices every employee against the same table.
 */
class TaxService
{
    /** @var array<string, array<int, array{min: float, base: float, rate: float}>> */
    private array $memo = [];

    /**
     * Annual tax on an annual taxable income, per the table in force on `$asOf`.
     *
     * `$asOf` is the cycle end, matching how salaries, contributions, allowances and the
     * attendance policy are all resolved. Null means "today", for callers with no period in hand.
     */
    public function annualTax(float $annualTaxable, ?string $asOf = null): float
    {
        if ($annualTaxable <= 0) {
            return 0.0;
        }

        $bands = $this->bandsAsOf($asOf ?? now(config('app.timezone_local'))->toDateString());

        if ($bands === []) {
            return 0.0;
        }

        // The last band whose floor the income reaches. Bands are ordered ascending, so this is
        // the highest one that applies.
        $band = null;
        foreach ($bands as $candidate) {
            if ($annualTaxable > $candidate['min']) {
                $band = $candidate;
            }
        }

        if ($band === null) {
            return 0.0; // below the first band's floor — the exempt tier
        }

        return round(max(0, $band['base'] + $band['rate'] * ($annualTaxable - $band['min'])), 2);
    }

    /**
     * The bands of the table in force on a date, ascending by floor.
     *
     * @return array<int, array{min: float, base: float, rate: float}>
     */
    public function bandsAsOf(string $asOf): array
    {
        if (isset($this->memo[$asOf])) {
            return $this->memo[$asOf];
        }

        $version = TaxVersion::with('brackets')
            ->whereDate('effective_from', '<=', $asOf)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first()
            // See the class docblock: the earliest table, not a refusal.
            ?? TaxVersion::with('brackets')->orderBy('effective_from')->orderBy('id')->first();

        return $this->memo[$asOf] = $version
            ? $version->brackets->map(fn ($b) => [
                'min' => (float) $b->min_annual,
                'base' => (float) $b->base_tax,
                'rate' => (float) $b->rate_on_excess,
            ])->values()->all()
            : [];
    }
}
