<?php

namespace App\Modules\Hr\Support;

/**
 * What the pricer needs to know that is not a setting and not a day: whose figure it is, and how
 * much of the month the priced days stand for.
 */
final class InternRateContext
{
    /** The days are one ordinary cutoff. A monthly allowance is spread across the year's cutoffs. */
    public const SHARE_PERIOD = 'period';

    /** The days are the whole month, paid on one cutoff. A monthly allowance lands in full. */
    public const SHARE_MONTH = 'month';

    /** Nothing is paid on this cutoff under the distribution setting. */
    public const SHARE_NONE = 'none';

    /**
     * @param  float  $amount  the allowance in the policy's unit (per day, per hour or per month)
     * @param  string  $amountSource  'override' (the intern's own) or 'default' (the company's)
     * @param  int  $scheduledWorkdays  workdays in the priced range — a monthly allowance's daily
     *                                  equivalent under the `period_workdays` basis
     */
    public function __construct(
        public readonly float $amount,
        public readonly string $amountSource,
        public readonly int $periodsPerYear,
        public readonly int $scheduledWorkdays,
        public readonly string $share = self::SHARE_PERIOD,
    ) {}
}
