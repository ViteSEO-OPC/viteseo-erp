<?php

namespace App\Modules\Hr\Services;

/**
 * The result of AttendanceService::unpaidDaysDetailFor() — the total unpaid days (as
 * always) PLUS the minute-level breakdown that step 4b persists onto the payslip so it
 * can explain itself. `unpaidDays` is the number payroll has always used; the rest is
 * additive detail that is all-zero until a deduction mode is enabled.
 */
final class UnpaidDaysResult
{
    /**
     * @param  float  $unpaidDays  total unpaid days for the period (the existing float)
     * @param  int  $lateMinutes  raw late minutes charged across the period
     * @param  int  $undertimeMinutes  raw undertime minutes charged across the period
     * @param  float  $lateDays  late portion expressed in days (× dailyRate ⇒ pesos)
     * @param  float  $undertimeDays  undertime portion in days
     * @param  list<string>  $flags  non-pay flags raised for HR review
     */
    public function __construct(
        public readonly float $unpaidDays,
        public readonly int $lateMinutes = 0,
        public readonly int $undertimeMinutes = 0,
        public readonly float $lateDays = 0.0,
        public readonly float $undertimeDays = 0.0,
        public readonly array $flags = [],
    ) {}
}
