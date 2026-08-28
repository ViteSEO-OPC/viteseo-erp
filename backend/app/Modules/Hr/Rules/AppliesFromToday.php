<?php

namespace App\Modules\Hr\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A rule change applies from today onward, never from a date already gone.
 *
 * Backdating used to be how HR corrected a period that had already been run: date the change
 * inside the old cycle, re-run it, done. That is precisely what this platform no longer does. A
 * period's rules seal when the period ends, and a period priced wrongly is fixed with a payslip
 * correction — which can subtract as well as add, and leaves a record of exactly what moved.
 * Backdating a rule instead reprices every payslip within reach at once, silently, and the
 * amount that moved is knowable only by re-deriving it.
 *
 * Today itself is allowed and reaches the whole of the period it falls in: payroll prices a
 * cycle at the rules in force on its LAST day, so a change saved today covers the cycle that is
 * still open, and seals when that cycle ends.
 */
class AppliesFromToday implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Omitted or blank means today, which is the intended way to use this field.
        if ($value === null || $value === '') {
            return;
        }

        try {
            // Manila day boundaries, per the platform rule. `config('app.timezone')` is UTC, so
            // a naive "today" would roll over eight hours early every evening and reject a date
            // HR is looking straight at.
            $zone = config('app.timezone_local');
            $given = CarbonImmutable::parse((string) $value, $zone)->startOfDay();
        } catch (\Throwable) {
            return; // not a date at all — that is the `date` rule's report to make, not this one's
        }

        if ($given->lessThan(CarbonImmutable::now($zone)->startOfDay())) {
            $fail(
                'A rule takes effect from today onward. To change a period that has already '
                .'been run, correct its payslips instead.'
            );
        }
    }
}
