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
    public function forPeriod(
        Employee $employee,
        int $periodsInMonth = 1,
        ?string $cycleStart = null,
        ?string $cycleEnd = null,
    ): array {
        $lines = [];
        $total = 0.0;

        // A deleted programme stops deducting: the soft-delete scope is the whole "is it live"
        // test since B13 item 4 removed `is_active`.
        $enrolments = $employee->benefitPrograms()
            ->whereNotNull('deduction_cadence')
            ->orderBy('name')
            ->get();

        // Slice B2 — an enrolment has dates now. Somebody who joins on the 20th is not charged for
        // the cycle that ended on the 15th, and a leaver stops on a date rather than by having
        // their row deleted (which would rewrite what past payslips can explain).
        //
        // WHOLE PERIODS, not part ones: if the enrolment overlaps the cycle at all, the full
        // instalment is charged. A plan premium is a monthly price, not an accrual — proration
        // would need the insurer's own rule, and guessing one is worse than a stated convention.
        if ($cycleEnd !== null) {
            // Compared as Y-m-d strings, which sort correctly — but only once both sides ARE
            // Y-m-d. A pivot date can arrive as "2026-08-20 00:00:00" depending on the driver,
            // and that sorts AFTER "2026-08-20", which would drop the very cycle somebody joined.
            $day = fn ($value) => $value === null ? null : substr((string) $value, 0, 10);

            $enrolments = $enrolments->filter(function ($program) use ($cycleStart, $cycleEnd, $day) {
                $from = $day($program->pivot->enrolled_at ?? null);
                $until = $day($program->pivot->ended_at ?? null);

                if ($from !== null && $from > $cycleEnd) {
                    return false; // joins after this cycle
                }

                return ! ($until !== null && $cycleStart !== null && $until < $cycleStart);
            });
        }

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
