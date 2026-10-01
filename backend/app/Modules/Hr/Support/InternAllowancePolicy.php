<?php

namespace App\Modules\Hr\Support;

use InvalidArgumentException;

/**
 * The company's intern allowance rules as of one date — an immutable reading of one
 * `hr_intern_allowance_versions` payload.
 *
 * Every POLICY choice is data here, because companies differ on how an intern's allowance is
 * earned, docked and paid; nothing about that is decided in code. What code does decide is
 * arithmetic, and that lives in normalise(): a combination of choices that would charge the same
 * minutes twice is corrected rather than stored.
 *
 * Grace periods and missing clock-outs are deliberately absent. Interns follow the work schedule
 * and the attendance policy exactly as employees do — a second, intern-only answer to either
 * would make payroll disagree with the attendance board about the same punch.
 */
final class InternAllowancePolicy
{
    /**
     * The choices each enumerated setting accepts. Validation reads these, so the API can never
     * accept a value the pricing engine has no branch for.
     *
     * @var array<string, list<string>>
     */
    public const OPTIONS = [
        // --- Allowance calculation ---
        'method' => ['per_day', 'per_hour', 'fixed_monthly'],
        'per_day_proration' => ['none', 'by_hours_rendered'],
        'monthly_proration' => ['none', 'by_working_days', 'by_hours_rendered'],

        // --- Attendance rules ---
        'late_rule' => ['none', 'missed_hours', 'fixed_amount', 'partial_after_minutes'],
        // How a day marked partial by `late_rule = partial_after_minutes` is priced.
        'partial_day_pricing' => ['half_day_rule', 'by_hours_rendered', 'no_allowance'],
        'undertime_rule' => ['none', 'missed_hours', 'fixed_amount'],
        'early_out_rule' => ['follow_undertime', 'no_deduction', 'half_day_rule'],
        'absence_rule' => ['no_allowance', 'absent_hours', 'no_deduction'],
        'half_day_rule' => ['pay_50', 'actual_hours', 'no_allowance', 'pay_full'],
        // The company's "every N lates costs a day" rule, applied to interns or not.
        'late_penalty_rule' => ['off', 'follow_company'],

        // --- Non-working days ---
        // What a premium day is WORTH before its premium rate is applied: the hours actually
        // rendered, or a whole day however much of it was worked. Companies differ — some treat
        // any rest-day work as a full day earned, others pay for the hours. Applies to every
        // premium day alike: a worked rest day, a worked holiday, a worked suspension.
        'premium_day_basis' => ['hours_rendered', 'whole_day'],
        'rest_day_worked' => ['no_allowance', 'allowance', 'allowance_with_premium'],
        'rest_day_premium_source' => ['company_rate', 'custom'],
        'regular_holiday_not_worked' => ['no_allowance', 'allowance'],
        'regular_holiday_worked' => ['allowance', 'allowance_with_premium', 'no_allowance'],
        'regular_holiday_rest_day_not_worked' => ['no_allowance', 'allowance'],
        'regular_holiday_premium_source' => ['company_rate', 'custom'],
        'special_holiday_not_worked' => ['no_allowance', 'allowance'],
        'special_holiday_worked' => ['allowance', 'allowance_with_premium', 'no_allowance'],
        'special_holiday_rest_day_not_worked' => ['no_allowance', 'allowance'],
        'special_holiday_premium_source' => ['company_rate', 'custom'],
        'special_working_day' => ['regular_workday', 'as_special_holiday'],
        // Company-declared non-working days (work suspensions).
        'suspension_not_worked' => ['no_allowance', 'allowance', 'follow_suspension'],
        'suspension_worked' => ['allowance', 'allowance_with_premium'],
        'suspension_premium_source' => ['company_rate', 'custom'],
        'overtime_rule' => ['not_paid', 'regular_rate', 'with_premium'],
        'overtime_premium_source' => ['company_rate', 'custom'],
        'night_diff_rule' => ['not_paid', 'company_rate', 'custom'],

        // --- Attendance requirement ---
        'below_min_attendance' => ['no_allowance_for_day', 'treat_as_half_day', 'prorate_by_hours', 'flag_only'],

        // --- Leave ---
        'leave_pay_rule' => ['unpaid', 'follow_leave_type'],
        'leave_types_allowed' => ['all', 'selected'],

        // --- Distribution within the regular payroll runs ---
        'distribution_timing' => ['every_cutoff', 'first_cutoff', 'last_cutoff'],
    ];

    /**
     * The premiums an intern can earn, keyed by prefix, each pointing at the setting that switches
     * it on. `{prefix}_premium_source` and `{prefix}_premium_pct` complete each one.
     *
     * @var array<string, string>
     */
    public const PREMIUMS = [
        'rest_day' => 'rest_day_worked',
        'regular_holiday' => 'regular_holiday_worked',
        'special_holiday' => 'special_holiday_worked',
        'suspension' => 'suspension_worked',
        'overtime' => 'overtime_rule',
    ];

    /**
     * What a company that has never opened the page gets. These only pre-fill the opening
     * version — every one of them is HR's to change. Amounts start at zero so nobody is paid a
     * figure the company never chose.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'method' => 'per_day',
        'required_hours_per_day' => 8.0,
        'daily_amount' => 0.0,
        'per_day_proration' => 'none',
        'hourly_amount' => 0.0,
        'max_paid_hours_per_day' => 8.0,
        'monthly_amount' => 0.0,
        'monthly_proration' => 'by_working_days',

        'late_rule' => 'none',
        'late_fixed_amount' => null,
        'late_partial_after_minutes' => null,
        'partial_day_pricing' => 'half_day_rule',
        'undertime_rule' => 'none',
        'undertime_fixed_amount' => null,
        'early_out_rule' => 'follow_undertime',
        'absence_rule' => 'no_allowance',
        'half_day_rule' => 'pay_50',
        'late_penalty_rule' => 'off',

        'premium_day_basis' => 'hours_rendered',
        'rest_day_worked' => 'no_allowance',
        'rest_day_premium_source' => 'company_rate',
        'rest_day_premium_pct' => null,
        'regular_holiday_not_worked' => 'allowance',
        'regular_holiday_worked' => 'allowance',
        'regular_holiday_rest_day_not_worked' => 'no_allowance',
        'regular_holiday_premium_source' => 'company_rate',
        'regular_holiday_premium_pct' => null,
        'special_holiday_not_worked' => 'no_allowance',
        'special_holiday_worked' => 'allowance',
        'special_holiday_rest_day_not_worked' => 'no_allowance',
        'special_holiday_premium_source' => 'company_rate',
        'special_holiday_premium_pct' => null,
        'special_working_day' => 'regular_workday',
        'holiday_forfeit_after_absence' => false,
        'suspension_not_worked' => 'follow_suspension',
        'suspension_worked' => 'allowance',
        'suspension_premium_source' => 'company_rate',
        'suspension_premium_pct' => null,
        'overtime_rule' => 'not_paid',
        'overtime_premium_source' => 'company_rate',
        'overtime_premium_pct' => null,
        'night_diff_rule' => 'not_paid',
        'night_diff_pct' => null,

        'min_attendance_pct' => 0,
        'below_min_attendance' => 'flag_only',

        'leave_pay_rule' => 'unpaid',
        'leave_types_allowed' => 'all',
        'leave_type_ids' => [],

        'distribution_timing' => 'every_cutoff',
    ];

    /** @param  array<string, mixed>  $values  a complete, normalised settings array */
    private function __construct(private readonly array $values) {}

    public static function defaults(): self
    {
        return new self(self::normalise(self::DEFAULTS)[0]);
    }

    /**
     * Read a stored payload. A setting added after the version was written takes its default,
     * so an old cycle re-run still prices — it cannot have been governed by a choice that did
     * not exist yet, and the default is what the code did before the choice existed.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        // Typed again on the way out: a MySQL JSON column hands `650.0` back as the integer 650,
        // and a pricing engine comparing amounts must not see a different type per version.
        return new self(self::typed(array_merge(self::DEFAULTS, array_intersect_key($payload, self::DEFAULTS))));
    }

    public function get(string $key): mixed
    {
        if (! array_key_exists($key, $this->values)) {
            throw new InvalidArgumentException("Unknown intern allowance setting [{$key}].");
        }

        return $this->values[$key];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Whether pay follows the hours actually rendered. When it does, minutes an intern did not
     * work are already unpaid — see normalise().
     */
    public function isHoursBased(): bool
    {
        return self::hoursBased($this->values);
    }

    /**
     * Correct a set of choices whose arithmetic would contradict itself, and tidy away detail
     * fields that belong to a choice not made.
     *
     * Only the ARITHMETIC corrections are reported back, so the page can say why a control it
     * showed as selected was saved differently. Tidying is silent: a daily amount typed and then
     * abandoned for a monthly method is not something HR needs explained.
     *
     * @param  array<string, mixed>  $input  validated settings (any subset; the rest default)
     * @return array{0: array<string, mixed>, 1: list<string>} the payload, and the keys corrected
     */
    public static function normalise(array $input): array
    {
        $v = array_merge(self::DEFAULTS, array_intersect_key($input, self::DEFAULTS));
        $corrected = [];

        $force = function (string $key, mixed $value) use (&$v, &$corrected): void {
            if ($v[$key] !== $value) {
                $v[$key] = $value;
                $corrected[] = $key;
            }
        };

        /*
         * Pay that follows the hours rendered has already left every missed minute unpaid. A late,
         * undertime or early-out deduction on top would charge those same minutes a second time,
         * and a half-day can only mean the hours actually worked.
         */
        if (self::hoursBased($v)) {
            $force('late_rule', 'none');
            $force('undertime_rule', 'none');
            $force('early_out_rule', 'no_deduction');
            $force('half_day_rule', 'actual_hours');
            if ($v['below_min_attendance'] === 'prorate_by_hours') {
                $force('below_min_attendance', 'flag_only');
            }
        }

        // An absent day's hours only differ from the day itself when a monthly figure is spread
        // across days. Paid by the day or the hour, an absent day is simply a day not paid.
        if ($v['absence_rule'] === 'absent_hours' && $v['method'] !== 'fixed_monthly') {
            $force('absence_rule', 'no_allowance');
        }

        // An allowance earned by the day or the hour cannot be paid before those days are rendered.
        if ($v['distribution_timing'] === 'first_cutoff' && $v['method'] !== 'fixed_monthly') {
            $force('distribution_timing', 'every_cutoff');
        }

        $clear = function (string $key) use (&$v): void {
            $v[$key] = self::DEFAULTS[$key];
        };

        if ($v['method'] !== 'per_day') {
            $clear('daily_amount');
            $clear('per_day_proration');
        }
        if ($v['method'] !== 'per_hour') {
            $clear('hourly_amount');
            $clear('max_paid_hours_per_day');
        }
        if ($v['method'] !== 'fixed_monthly') {
            $clear('monthly_amount');
            $clear('monthly_proration');
        }
        if ($v['late_rule'] !== 'fixed_amount') {
            $clear('late_fixed_amount');
        }
        if ($v['late_rule'] !== 'partial_after_minutes') {
            $clear('late_partial_after_minutes');
            $clear('partial_day_pricing');
        }
        if ($v['undertime_rule'] !== 'fixed_amount') {
            $clear('undertime_fixed_amount');
        }
        foreach (self::PREMIUMS as $prefix => $ruleKey) {
            if (! in_array($v[$ruleKey], ['allowance_with_premium', 'with_premium'], true)) {
                $clear("{$prefix}_premium_source");
            }
            if ($v["{$prefix}_premium_source"] !== 'custom') {
                $clear("{$prefix}_premium_pct");
            }
        }
        if ($v['night_diff_rule'] !== 'custom') {
            $clear('night_diff_pct');
        }
        if ((int) $v['min_attendance_pct'] === 0) {
            $clear('below_min_attendance');
        }
        if ($v['leave_types_allowed'] !== 'selected') {
            $clear('leave_type_ids');
        }

        return [self::typed($v), $corrected];
    }

    /** @param  array<string, mixed>  $v */
    private static function hoursBased(array $v): bool
    {
        return match ($v['method']) {
            'per_hour' => true,
            'per_day' => $v['per_day_proration'] === 'by_hours_rendered',
            'fixed_monthly' => $v['monthly_proration'] === 'by_hours_rendered',
            default => false,
        };
    }

    /**
     * Store numbers as numbers. Request input arrives as strings, and a payload that says "650"
     * in one version and 650 in the next would show as a change nobody made.
     *
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private static function typed(array $v): array
    {
        $money = static fn (mixed $x): ?float => $x === null ? null : round((float) $x, 2);

        foreach (['daily_amount', 'hourly_amount', 'monthly_amount'] as $key) {
            $v[$key] = (float) $money($v[$key]);
        }
        foreach (['late_fixed_amount', 'undertime_fixed_amount'] as $key) {
            $v[$key] = $money($v[$key]);
        }
        foreach ([...array_map(fn ($p) => "{$p}_premium_pct", array_keys(self::PREMIUMS)), 'night_diff_pct'] as $key) {
            $v[$key] = $v[$key] === null ? null : round((float) $v[$key], 2);
        }

        $v['required_hours_per_day'] = round((float) $v['required_hours_per_day'], 2);
        $v['max_paid_hours_per_day'] = round((float) $v['max_paid_hours_per_day'], 2);
        $v['late_partial_after_minutes'] = $v['late_partial_after_minutes'] === null
            ? null
            : (int) $v['late_partial_after_minutes'];
        $v['min_attendance_pct'] = (int) $v['min_attendance_pct'];
        $v['holiday_forfeit_after_absence'] = (bool) $v['holiday_forfeit_after_absence'];
        $v['leave_type_ids'] = array_values(array_unique(array_map('intval', (array) $v['leave_type_ids'])));

        return $v;
    }
}
