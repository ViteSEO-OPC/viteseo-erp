<?php

namespace App\Modules\Hr\Services;

/**
 * The result of pricing ONE worked day's late/undertime shortfall (DeductionService).
 *
 * Money is expressed as a FRACTION of one day so it flows through unpaidDaysFor()'s
 * existing float accumulator unchanged — a full day is 1.0, so a payslip, report and
 * CSV never learn that minutes were involved. `unpaidFraction` is the single source of
 * truth for money; the component minutes/fractions exist only so the payslip (step 7)
 * can show "Late 30 min · Undertime 0" instead of one opaque number.
 *
 * Invariant (asserted by the caller/tests): lateMinutes + undertimeMinutes == the raw
 * shortfall that produced this result, so the components can never silently double-count.
 */
final class DeductionResult
{
    /**
     * @param  float  $unpaidFraction  the capped, rounded fraction of a day to deduct (0..cap)
     * @param  int  $lateMinutes  raw late minutes charged (0 when the late mode is off)
     * @param  int  $undertimeMinutes  raw undertime minutes charged (0 when the mode is off)
     * @param  float  $lateFraction  late portion of the fraction, for display
     * @param  float  $undertimeFraction  undertime portion of the fraction, for display
     * @param  list<string>  $flags  non-pay flags raised for HR review (e.g. 'long_break')
     */
    public function __construct(
        public readonly float $unpaidFraction,
        public readonly int $lateMinutes = 0,
        public readonly int $undertimeMinutes = 0,
        public readonly float $lateFraction = 0.0,
        public readonly float $undertimeFraction = 0.0,
        public readonly array $flags = [],
    ) {}

    /** A day that costs nothing and raises no flags — the default/no-op result. */
    public static function zero(): self
    {
        return new self(0.0);
    }
}
