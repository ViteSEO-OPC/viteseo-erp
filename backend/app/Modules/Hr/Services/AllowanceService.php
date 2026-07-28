<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Employee;

/**
 * What an employee's allowances are worth per month, read from Company → Benefits (Slice B1).
 *
 * WHY THIS EXISTS. Allowances lived in two places that disagreed: the Benefits page showed four
 * programmes (₱77,000/month across the company) while payroll paid three hard-coded columns on
 * `hr_employees` (₱42,000/month). Editing the page changed nobody's payslip, and Gas Allowance had
 * no column at all so it could never be paid. Benefits is now the single source of truth.
 *
 * PRECEDENCE — the part that decides whether editing a programme reaches people already enrolled:
 *
 *   pivot `amount` IS NULL  →  use the programme's amount   (the normal case: edit the programme,
 *                                                            everyone enrolled moves with it)
 *   pivot `amount` NOT NULL →  use the pivot                (a deliberate per-employee override)
 *
 * The migration that switched payroll over wrote every employee's OLD column value onto the pivot,
 * so the first run after the switch paid exactly what the old columns did. Those pinned values are
 * transitional: the reconciliation report shows each one against its programme amount, and clearing
 * an override hands that employee back to the programme.
 */
class AllowanceService
{
    /**
     * The employee's total monthly allowance. Payroll spreads this across the pay periods, so it
     * must be a MONTHLY figure regardless of how a programme is described.
     */
    public function monthlyFor(Employee $employee): float
    {
        return round(array_sum(array_column($this->linesFor($employee), 'amount')), 2);
    }

    /**
     * One line per enrolled allowance, with where its amount came from. Drives the reconciliation
     * report and lets a payslip explain itself later.
     *
     * @return array<int, array{program_id:int, name:string, amount:float, programme_amount:float, is_override:bool, tax_treatment:string}>
     */
    public function linesFor(Employee $employee): array
    {
        $programmes = $employee->benefitPrograms()
            ->where('kind', 'allowance')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $lines = [];

        foreach ($programmes as $programme) {
            $override = $programme->pivot->amount;
            $programmeAmount = (float) ($programme->amount ?? 0);
            $amount = $override !== null ? (float) $override : $programmeAmount;

            $lines[] = [
                'program_id' => (int) $programme->id,
                'name' => (string) $programme->name,
                'amount' => $this->toMonthly($amount, (string) $programme->frequency),
                'programme_amount' => $this->toMonthly($programmeAmount, (string) $programme->frequency),
                'is_override' => $override !== null,
                // Slice B4 — payroll needs this to know which allowances are compensation.
                // Unknown values fall back to `de_minimis`, matching the column default: guessing
                // "taxable" for a value we don't understand would over-withhold.
                'tax_treatment' => $programme->tax_treatment === 'taxable' ? 'taxable' : 'de_minimis',
            ];
        }

        return $lines;
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

    /**
     * Every employee whose pinned allowance differs from what their programme says (Slice B1).
     *
     * This is the working list for the switch: each row is a decision HR has to make, not a bug to
     * fix silently. Three shapes turned up on the live data — someone paid ABOVE their programme,
     * several paid NOTHING despite being enrolled, and a programme (Gas) that has never been paid
     * to anyone. Clearing an override hands that employee back to the programme amount.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reconciliation(): array
    {
        $rows = [];

        foreach (Employee::with('user')->get() as $employee) {
            $lines = array_values(array_filter(
                $this->linesFor($employee),
                fn ($l) => $l['is_override'] && abs($l['amount'] - $l['programme_amount']) >= 0.01,
            ));

            if ($lines === []) {
                continue;
            }

            $paid = array_sum(array_column($lines, 'amount'));
            $programme = array_sum(array_column($lines, 'programme_amount'));

            $rows[] = [
                'employee_id' => (int) $employee->id,
                'employee' => $employee->user?->name ?? $employee->name,
                'paid_monthly' => round($paid, 2),
                'programme_monthly' => round($programme, 2),
                'delta' => round($paid - $programme, 2),
                'lines' => $lines,
            ];
        }

        // Biggest gap first — the decisions that move the most money.
        usort($rows, fn ($a, $b) => abs($b['delta']) <=> abs($a['delta']));

        return $rows;
    }

    /**
     * Hand an employee back to the programme amount for one allowance, by clearing their override.
     * The one action the reconciliation report offers.
     */
    public function clearOverride(Employee $employee, int $programId): void
    {
        $employee->benefitPrograms()->updateExistingPivot($programId, ['amount' => null]);
    }

    /**
     * The allowance programmes a new hire should start on: active, and open to everyone.
     *
     * `EmployeeService::create()` seeds the statutory contribution checklist but never enrolled
     * anyone in a benefit programme — harmless while payroll read the employee's own columns, but
     * under Slice B1 it would have meant every new hire silently earning no allowances at all.
     * `eligibility = 'regular'` and `'custom'` are deliberately excluded: those are HR's call.
     *
     * @return array<int, int>
     */
    public function defaultProgrammeIdsForNewHire(): array
    {
        return BenefitProgram::where('kind', 'allowance')
            ->where('is_active', true)
            ->where('eligibility', 'all')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
