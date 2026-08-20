<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;

/** What an employee's allowances are worth per month, read from Company → Benefits. */
class AllowanceService
{
    public function __construct(private readonly BenefitProgramService $programs) {}

    /**
     * The employee's total monthly allowance. Payroll spreads this across the pay periods, so it
     * must be a MONTHLY figure regardless of how a programme is described.
     */
    public function monthlyFor(Employee $employee, ?string $asOf = null): float
    {
        return round(array_sum(array_column($this->linesFor($employee, $asOf), 'amount')), 2);
    }

    /** The slice of the monthly allowance that counts toward the statutory contribution base */
    public function monthlyContributableFor(Employee $employee, ?string $asOf = null): float
    {
        $lines = array_filter(
            $this->linesFor($employee, $asOf),
            fn (array $line) => $line['contributable'],
        );

        return round(array_sum(array_column($lines, 'amount')), 2);
    }

    /**
     * One line per enrolled allowance. Lets a payslip name what made it up rather than showing one
     * opaque figure.
     *
     * @return array<int, array{program_id:int, name:string, amount:float, tax_treatment:string, deminimis_ceiling:mixed, ceiling_period:?string, contributable:bool}>
     */
    public function linesFor(Employee $employee, ?string $asOf = null): array
    {
        // The programmes whose RULE covers this employee ON THIS CYCLE — not a stored enrolment
        // list. A status change is therefore followed automatically, which is the drift B5 item 3
        // and B10 both described.
        $programmes = $this->programs->allowancesFor($employee, $asOf);
        $overrides = $this->programs->overridesFor($employee);

        // What each allowance was worth ON THIS CYCLE, not today. Re-running an old
        // period used to price it at the current figure, so a September rise silently rewrote
        // August. Null asOf ⇒ today, which is what every screen wants.
        $pricing = $asOf === null ? [] : $this->programs->pricingAsOf($asOf);

        $lines = [];

        foreach ($programmes as $programme) {
            // A deliberate per-employee amount wins over the programme's. Null (the
            // normal case) means this person follows the programme, so an edit there reaches them.
            $version = $pricing[(int) $programme->id] ?? null;

            // A deliberate per-employee amount still wins: it is a figure a person typed for
            // this employee, not a company-wide price, so it is not versioned alongside one.
            $amount = $overrides[(int) $programme->id]
                ?? ($version->amount ?? $programme->amount)
                ?? 0;
            $frequency = (string) ($version->frequency ?? $programme->frequency);

            $lines[] = [
                'program_id' => (int) $programme->id,
                'name' => (string) $programme->name,
                'amount' => $this->toMonthly((float) $amount, $frequency),
                // Payroll needs this to know which allowances are compensation.
                // Unknown values fall back to `de_minimis`, matching the column default: guessing
                // "taxable" for a value we don't understand would over-withhold.
                'tax_treatment' => ($version->tax_treatment ?? $programme->tax_treatment) === 'taxable'
                    ? 'taxable'
                    : 'de_minimis',
                // This benefit's own statutory ceiling, and the period it is stated
                // in. Null means none recorded, which keeps the whole amount exempt.
                'deminimis_ceiling' => $version->deminimis_ceiling ?? $programme->deminimis_ceiling,
                'ceiling_period' => $version->ceiling_period ?? $programme->ceiling_period,
                // Does this allowance count toward the statutory contribution base?
                // Read from the dated version first for the same reason the amount is: a switch
                // flipped in September must not change what August was charged on.
                'contributable' => (bool) ($version
                    ? ($version->contributable ?? true)
                    : ($programme->contributable ?? true)),
            ];
        }

        return $lines;
    }

    /**
     * The employee's monthly allowances split into the three things tax does with them.
     *
     * @return array{exempt: float, other_benefits: float, taxable: float}
     */
    public function monthlyTaxBucketsFor(Employee $employee, ?string $asOf = null): array
    {
        $split = ['exempt' => 0.0, 'other_benefits' => 0.0, 'taxable' => 0.0];

        foreach ($this->linesFor($employee, $asOf) as $line) {
            if ($line['tax_treatment'] === 'taxable') {
                $split['taxable'] += $line['amount'];

                continue;
            }

            $ceiling = $this->monthlyCeiling($line['deminimis_ceiling'], $line['ceiling_period']);

            if ($ceiling === null) {
                $split['exempt'] += $line['amount'];

                continue;
            }

            $split['exempt'] += min($line['amount'], $ceiling);
            $split['other_benefits'] += max(0.0, $line['amount'] - $ceiling);
        }

        return [
            'exempt' => round($split['exempt'], 2),
            'other_benefits' => round($split['other_benefits'], 2),
            'taxable' => round($split['taxable'], 2),
        ];
    }

    /**
     * A ceiling as a MONTHLY figure. The BIR states them per month, per semester and per year;
     * normalising by hand in each place that needs one is where transcription errors live.
     */
    private function monthlyCeiling(mixed $ceiling, ?string $period): ?float
    {
        if ($ceiling === null) {
            return null;
        }

        return round(match ($period) {
            'annual', 'yearly' => (float) $ceiling / 12,
            'semester' => (float) $ceiling / 6,
            default => (float) $ceiling, // 'monthly', and anything unrecognised
        }, 2);
    }

    /**
     * The employee's monthly allowance split by tax treatment.
     *
     * @return array{de_minimis: float, taxable: float}
     */
    public function monthlyByTaxTreatmentFor(Employee $employee): array
    {
        $split = ['de_minimis' => 0.0, 'taxable' => 0.0];

        foreach ($this->linesFor($employee) as $line) {
            $split[$line['tax_treatment']] += $line['amount'];
        }

        return ['de_minimis' => round($split['de_minimis'], 2), 'taxable' => round($split['taxable'], 2)];
    }

    /** Normalise a programme's amount to a monthly figure. */
    private function toMonthly(float $amount, string $frequency): float
    {
        return round(match ($frequency) {
            'annual', 'yearly' => $amount / 12,
            'semi_monthly' => $amount * 2,
            'weekly' => $amount * 52 / 12,
            default => $amount, // 'monthly' and anything unrecognised
        }, 2);
    }

}
