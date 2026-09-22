<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;

/**
 * Recurring, non-statutory deductions from Company → Benefits: the employee's
 * share of a benefit they are enrolled in — HMO, union dues, a retirement plan.
 */
class BenefitDeductionService
{
    /**
     * This employee's recurring benefit deductions for ONE pay period.
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
        // Nothing is taken off an intern's allowance but what attendance takes (Company → Intern
        // Allowance) — no benefit shares, no dues.
        if ($employee->isIntern()) {
            return ['total' => 0.0, 'lines' => []];
        }

        $lines = [];
        $total = 0.0;

        // A deleted programme stops deducting: the soft-delete scope is the whole "is it live"
        // test since B13 item 4 removed `is_active`.
        $enrolments = $employee->benefitPrograms()
            ->whereNotNull('deduction_cadence')
            ->orderBy('name')
            ->get();

        // An enrolment has dates now. Somebody who joins on the 20th is not charged for
        // the cycle that ended on the 15th, and a leaver stops on a date rather than by having
        // their row deleted (which would rewrite what past payslips can explain).
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
