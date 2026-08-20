<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\TaxVersion;

/** Annual withholding tax, from the table that was in force for the period being priced. */
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
     * The band an annual taxable income falls in, or null when it is below the first floor
     * (the exempt tier) or no table exists.
     *
     * @return array{min: float, base: float, rate: float}|null
     */
    public function bandFor(float $annualTaxable, ?string $asOf = null): ?array
    {
        if ($annualTaxable <= 0) {
            return null;
        }

        $band = null;
        foreach ($this->bandsAsOf($asOf ?? now(config('app.timezone_local'))->toDateString()) as $candidate) {
            if ($annualTaxable > $candidate['min']) {
                $band = $candidate;
            }
        }

        return $band;
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
