<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;

/**
 * What an employee's allowances are worth per month, read from Company → Benefits (Slice B1).
 *
 * WHY THIS EXISTS. Allowances lived in two places that disagreed: the Benefits page showed four
 * programmes (₱77,000/month across the company) while payroll paid three hard-coded columns on
 * `hr_employees` (₱42,000/month). Editing the page changed nobody's payslip, and Gas Allowance had
 * no column at all so it could never be paid. Benefits is now the single source of truth.
 *
 * ONE AMOUNT PER ALLOWANCE, UNLESS SOMEONE DELIBERATELY SAYS OTHERWISE (B6, amended by B14 on
 * 2026-08-03). The programme's amount is what everybody gets, so editing it reaches everybody —
 * that is the property this whole audit exists to guarantee. A per-employee figure exists only
 * where a person typed one, and it is stamped with who and when.
 *
 * The difference from the bug B1 shipped is provenance, not mechanism: B1's migration wrote an
 * amount onto all 56 enrolments, so "has its own amount" meant nothing and the Benefits page drove
 * nobody's pay. Here the column is written by a person or not at all.
 */
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

    /**
     * One line per enrolled allowance. Lets a payslip name what made it up rather than showing one
     * opaque figure.
     *
     * @return array<int, array{program_id:int, name:string, amount:float, tax_treatment:string, deminimis_ceiling:mixed, ceiling_period:?string}>
     */
    public function linesFor(Employee $employee, ?string $asOf = null): array
    {
        // The programmes whose RULE covers this employee today — not a stored enrolment list.
        // A status change is therefore followed automatically, which is the drift B5 item 3 and
        // B10 both described.
        $programmes = $this->programs->allowancesFor($employee);
        $overrides = $this->programs->overridesFor($employee);

        // Slice B11 — what each allowance was worth ON THIS CYCLE, not today. Re-running an old
        // period used to price it at the current figure, so a September rise silently rewrote
        // August. Null asOf ⇒ today, which is what every screen wants.
        $pricing = $asOf === null ? [] : $this->programs->pricingAsOf($asOf);

        $lines = [];

        foreach ($programmes as $programme) {
            // Slice B14 — a deliberate per-employee amount wins over the programme's. Null (the
            // normal case) means this person follows the programme, so an edit there reaches them.
            $version = $pricing[(int) $programme->id] ?? null;

            // A deliberate per-employee amount (B14) still wins: it is a figure a person typed for
            // this employee, not a company-wide price, so it is not versioned alongside one.
            $amount = $overrides[(int) $programme->id]
                ?? ($version->amount ?? $programme->amount)
                ?? 0;
            $frequency = (string) ($version->frequency ?? $programme->frequency);

            $lines[] = [
                'program_id' => (int) $programme->id,
                'name' => (string) $programme->name,
                'amount' => $this->toMonthly((float) $amount, $frequency),
                // Slice B4 — payroll needs this to know which allowances are compensation.
                // Unknown values fall back to `de_minimis`, matching the column default: guessing
                // "taxable" for a value we don't understand would over-withhold.
                'tax_treatment' => ($version->tax_treatment ?? $programme->tax_treatment) === 'taxable'
                    ? 'taxable'
                    : 'de_minimis',
                // Slice B16 — this benefit's own statutory ceiling, and the period it is stated
                // in. Null means none recorded, which keeps the whole amount exempt.
                'deminimis_ceiling' => $version->deminimis_ceiling ?? $programme->deminimis_ceiling,
                'ceiling_period' => $version->ceiling_period ?? $programme->ceiling_period,
            ];
        }

        return $lines;
    }

    /**
     * The employee's monthly allowances split into the three things tax does with them (B16).
     *
     * THE CHAIN, which the old single-cap version collapsed into two steps:
     *
     *   exempt          a de-minimis benefit UP TO its own statutory ceiling. Ceilings are per
     *                   item and cannot be pooled — a small uniform allowance does not create
     *                   room for a large rice subsidy.
     *   other_benefits  the amount ABOVE that ceiling. NOT taxable by itself: it joins the
     *                   13th-month-and-other-benefits bucket, which is exempt up to ₱90,000 a
     *                   year. Taxing it directly over-withheld for everyone under that figure.
     *   taxable         an allowance HR marked taxable — compensation in full, no ceiling.
     *
     * A de-minimis programme with NO ceiling recorded is treated as fully exempt, which is what
     * it does today: guessing a statutory limit for an allowance nobody has classified would
     * change pay on an assumption.
     *
     * The three buckets sum to `monthlyFor()` exactly — every line is already 2dp, so splitting
     * them cannot move gross pay.
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
     * The employee's monthly allowance split by tax treatment (Slice B4).
     *
     * WHY THIS EXISTS. Every allowance programme has a de-minimis / taxable switch that HR can set,
     * and payroll ignored it — everything was treated as tax-free. Nothing was marked taxable yet,
     * so nobody was affected, but the moment someone flipped that switch the company would have
     * been under-withholding with no sign that anything was wrong.
     *
     * The two halves are taxed differently, which is the whole point of the split:
     *   de_minimis → tax-free up to the company's annual ceiling; only the EXCESS is taxable
     *   taxable    → compensation in full, and the ceiling does not apply to it
     *
     * The buckets sum to `monthlyFor()` exactly — every line amount is already rounded to two
     * decimals, so partitioning them cannot introduce a rounding difference in gross pay.
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

    /**
     * Normalise a programme's amount to a monthly figure.
     *
     * `frequency` is validated only as a free-text string (`['sometimes','string','max:20']`), so
     * an unrecognised value is possible. Unknown ⇒ treat as monthly, which is what every existing
     * programme is — guessing a divisor for a value we don't understand would silently change pay.
     */
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
