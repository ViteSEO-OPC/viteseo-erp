<?php

namespace App\Modules\Hr\Services;

/** The result of pricing ONE worked day's late/undertime shortfall (DeductionService). */
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
