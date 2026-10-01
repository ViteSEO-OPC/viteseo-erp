<?php

namespace App\Modules\Hr\Support;

/**
 * One date an intern's allowance is priced over: what the day WAS, and nothing about what it
 * costs. AttendanceService::internDaysFor() reads it off the attendance record exactly as it reads
 * anyone's day; InternAllowancePricer decides its price from Company → Intern Allowance.
 *
 * Kept free of models so the calculation preview can price an invented month through the very
 * same pricer that prices a real one.
 */
final class InternDay
{
    /** A scheduled workday before the hire date or after the last working day. */
    public const NOT_EMPLOYED = 'not_employed';

    /** Today or later, with nothing recorded yet — not an absence until the day has passed. */
    public const PENDING = 'pending';

    public const ABSENT = 'absent';

    public const WORKED = 'worked';

    /** An approved half-day request, a half-day leave, or HR marking the day a half day. */
    public const HALF_DAY = 'half_day';

    /** A full day of approved leave. */
    public const LEAVE = 'leave';

    /** A holiday in the company calendar, whether or not it fell on a workday. */
    public const HOLIDAY = 'holiday';

    /** A company-declared non-working day (work suspension), or HR suspending this one day. */
    public const SUSPENSION = 'suspension';

    /** A rest day that was worked. */
    public const REST_DAY = 'rest_day';

    /** HR marked the day "rest day" or "holiday" for this one person — not a working day. */
    public const DAY_OFF = 'day_off';

    /**
     * @param  list<string>  $flags  review flags raised while reading the day (e.g. missing_clockout)
     */
    public function __construct(
        public readonly string $date,
        public readonly string $kind,
        public readonly bool $isWorkday,
        public readonly bool $isPast = true,
        public readonly bool $worked = false,
        public readonly int $scheduledMinutes = 0,
        public readonly int $workedMinutes = 0,
        public readonly int $lateMinutes = 0,
        public readonly int $lateChargeMinutes = 0,
        public readonly int $undertimeMinutes = 0,
        public readonly int $undertimeChargeMinutes = 0,
        public readonly int $earlyOutMinutes = 0,
        public readonly int $halves = 0,
        public readonly bool $isLeave = false,
        public readonly ?bool $leavePaid = null,
        public readonly ?string $holidayType = null,
        public readonly ?string $holidayName = null,
        public readonly ?bool $suspensionPaid = null,
        public readonly bool $byHr = false,
        public readonly int $overtimeMinutes = 0,
        public readonly int $nightMinutes = 0,
        public readonly ?string $detail = null,
        public readonly array $flags = [],
    ) {}

    /** @param  array<string, mixed>  $changes */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
