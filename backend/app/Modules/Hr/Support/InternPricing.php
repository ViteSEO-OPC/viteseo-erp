<?php

namespace App\Modules\Hr\Support;

/**
 * An intern's priced allowance for a run of days, and the payslip columns it becomes.
 *
 * The allowance is written into the SAME columns a salary uses — `period_base` for the allowance,
 * `absence_deduction` for what attendance took off it, the charge ledger in `attendance_lines` —
 * so the payslip document, the register and every export read an intern's slip without a branch
 * of their own. `is_intern` and `intern_basis` are what tell them it was an allowance.
 */
final class InternPricing
{
    /**
     * @param  list<array{date: string, kind: string, days: float, detail: string|null, amount: float}>  $charges
     * @param  list<array<string, mixed>>  $holidayLines
     * @param  list<array{date: string, code: string, detail: string|null}>  $flags
     * @param  array<string, bool>  $premiums  which premium lines the rules can pay at all
     * @param  array<string, mixed>  $settings  the normalised rules the days were priced under
     */
    public function __construct(
        public readonly string $method,
        public readonly float $amount,
        public readonly string $amountSource,
        public readonly float $dailyRate,
        public readonly float $hourlyRate,
        public readonly float $requiredHoursPerDay,
        public readonly float $periodBase,
        public readonly array $charges,
        public readonly float $holidayPay,
        public readonly array $holidayLines,
        public readonly float $restDayPay,
        public readonly int $restDayMinutes,
        public readonly float $overtimePay,
        public readonly int $overtimeMinutes,
        public readonly float $nightDiffPay,
        public readonly int $nightDiffMinutes,
        public readonly int $workingDays,
        public readonly int $renderedMinutes,
        public readonly int $paidMinutes,
        public readonly int $lateMinutes,
        public readonly int $undertimeMinutes,
        public readonly string $note,
        public readonly array $flags,
        public readonly array $premiums,
        public readonly array $settings,
    ) {}

    /** What attendance took off the allowance — never more than the allowance itself. */
    public function chargeTotal(): float
    {
        return round(min($this->periodBase, array_sum(array_column($this->charges, 'amount'))), 2);
    }

    /** The allowance actually earned, before premiums. */
    public function netAllowance(): float
    {
        return round($this->periodBase - $this->chargeTotal(), 2);
    }

    /** Everything this pricing pays: the net allowance plus every premium line. */
    public function earnings(): float
    {
        return round($this->netAllowance() + $this->holidayPay + $this->restDayPay + $this->overtimePay + $this->nightDiffPay, 2);
    }

    /**
     * The payslip columns this pricing fills. Contributions, tax, other deductions and 13th month
     * are left to the caller, which zeroes them for an intern.
     *
     * @return array<string, mixed>
     */
    public function toSlipFields(): array
    {
        $absence = $this->chargeTotal();
        $late = round(min($this->amountOf('late'), $absence), 2);
        $undertime = round(min($this->amountOf('undertime'), max(0.0, $absence - $late)), 2);

        return [
            'period_base' => round($this->periodBase, 2),
            'absence_deduction' => $absence,
            'base_salary' => $this->netAllowance(),
            'late_deduction' => $late,
            'undertime_deduction' => $undertime,
            'unpaid_days' => round(array_sum(array_column($this->charges, 'days')), 2),
            'late_penalty_days' => round($this->daysOf('late_penalty'), 2),
            'daily_rate' => round($this->dailyRate, 2),
            'working_days' => $this->workingDays,
            'late_minutes' => $this->lateMinutes,
            'undertime_minutes' => $this->undertimeMinutes,
            'holiday_premium' => round($this->holidayPay, 2),
            'rest_day_premium' => round($this->restDayPay, 2),
            'rest_day_minutes' => $this->restDayMinutes,
            'overtime_pay' => round($this->overtimePay, 2),
            'overtime_minutes' => $this->overtimeMinutes,
            'night_diff_pay' => round($this->nightDiffPay, 2),
            'night_diff_minutes' => $this->nightDiffMinutes,
            'attendance_lines' => $this->charges ?: null,
            'holiday_lines' => $this->holidayLines ?: null,
            'is_intern' => true,
            'intern_basis' => [
                'version' => 1,
                'method' => $this->method,
                'amount' => round($this->amount, 2),
                'amount_source' => $this->amountSource,
                'daily_equivalent' => round($this->dailyRate, 2),
                'hourly_equivalent' => round($this->hourlyRate, 2),
                'required_hours_per_day' => $this->requiredHoursPerDay,
                'working_days' => $this->workingDays,
                'rendered_minutes' => $this->renderedMinutes,
                'paid_minutes' => $this->paidMinutes,
                'note' => $this->note,
                'premiums' => $this->premiums,
                'flags' => $this->flags,
                'settings' => $this->settings,
            ],
        ];
    }

    private function amountOf(string $kind): float
    {
        return array_sum(array_map(
            fn (array $c) => $c['kind'] === $kind ? (float) $c['amount'] : 0.0,
            $this->charges,
        ));
    }

    private function daysOf(string $kind): float
    {
        return array_sum(array_map(
            fn (array $c) => $c['kind'] === $kind ? (float) $c['days'] : 0.0,
            $this->charges,
        ));
    }
}
