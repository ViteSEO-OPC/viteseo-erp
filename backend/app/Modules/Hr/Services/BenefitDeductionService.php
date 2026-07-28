<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;

/**
 * Recurring, non-statutory deductions from Company → Benefits (Slice 11a): the employee's
 * share of a benefit they are enrolled in — HMO, union dues, a retirement plan.
 *
 * WHY THIS IS SMALL. The whole shape already existed and payroll simply never read it:
 * `hr_benefit_programs` is the catalogue, `hr_benefit_enrollments.employee_share` is what the
 * employee pays, and `deduction_cadence` says how often. Until now the Benefits page was a
 * brochure — an HMO could be marked `per_cutoff` and nothing was ever deducted.
 *
 * NOT loans or cash advances. Those need a running balance that decrements to zero and are
 * per-employee, not a company-wide programme — modelling one as a "benefit programme" would
 * mean a new programme per loan. They get their own table (Slice 11b).
 *
 * Statutory contributions (SSS / PhilHealth / Pag-IBIG) are NOT here — they have their own
 * config, brackets and employer share via ContributionService.
 */
class BenefitDeductionService
{
    /**
     * This employee's recurring benefit deductions for ONE pay period.
     *
     * `deduction_cadence` decides the amount:
     *   - `per_cutoff` → the share is charged on every payslip
     *   - `monthly`    → the share is a MONTHLY figure, so it is spread across the month's
     *                    periods (twice a month ⇒ half each), matching how statutory
     *                    contributions are distributed
     *   - null         → the programme isn't a payroll deduction at all; skipped
     *
     * @param  int  $periodsInMonth  how many periods this calendar month has (from PayScheduleService)
     * @return array{total: float, lines: array<int, array{name: string, amount: float}>}
     */
    public function forPeriod(Employee $employee, int $periodsInMonth = 1): array
    {
        $lines = [];
        $total = 0.0;

        $enrolments = $employee->benefitPrograms()
            ->where('is_active', true)
            ->whereNotNull('deduction_cadence')
            ->orderBy('name')
            ->get();

        foreach ($enrolments as $program) {
            // The pivot carries the per-employee amount; a null/zero share means the company
            // pays the whole thing, which is the common case for HMO.
            $share = (float) ($program->pivot->employee_share ?? 0);
            if ($share <= 0) {
                continue;
            }

            $amount = $program->deduction_cadence === 'monthly'
                ? round($share / max(1, $periodsInMonth), 2)
                : round($share, 2);

            if ($amount <= 0) {
                continue;
            }

            $lines[] = ['name' => (string) $program->name, 'amount' => $amount];
            $total += $amount;
        }

        return ['total' => round($total, 2), 'lines' => $lines];
    }
}
