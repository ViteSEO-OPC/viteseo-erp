<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollSetting;
use Illuminate\Support\Carbon;

/**
 * Derives pay periods from the company payroll schedule — the single source of
 * truth for "when payroll happens". Supports multiple frequencies at once
 * (pay groups): each employee's `pay_frequency` (falling back to the company
 * default) puts them in a group that's run on its own period.
 */
class PayScheduleService
{
    /** How many pay periods a given frequency has per year (drives per-period pay). */
    public const PERIODS_PER_YEAR = [
        'monthly' => 12,
        'semi_monthly' => 24,
        'bi_weekly' => 26,
        'weekly' => 52,
    ];

    public static function periodsPerYear(string $frequency): int
    {
        return self::PERIODS_PER_YEAR[$frequency] ?? 24;
    }

    /** The pay period covering `ref` for the company's default frequency. */
    public function currentPeriod(PayrollSetting $setting, ?Carbon $ref = null): array
    {
        return $this->currentPeriodFor($setting->frequency, $setting->anchor_date, $setting->pay_delay_days ?? 0, $ref);
    }

    /** The pay period covering `ref` for a specific frequency (pay group). */
    public function currentPeriodFor(string $frequency, ?Carbon $anchor, int $payDelay = 0, ?Carbon $ref = null): array
    {
        $ref = $ref ?? now(config('app.timezone_local'));
        [$start, $end] = $this->periodBounds($frequency, $anchor, $ref);

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'pay_date' => $end->copy()->addDays($payDelay)->toDateString(),
        ];
    }

    /**
     * The next upcoming pay date for a pay group: today's period if its pay date
     * hasn't passed yet, otherwise the following period's pay date. Frequency-agnostic.
     */
    public function nextPayDate(string $frequency, ?Carbon $anchor, int $payDelay = 0, ?Carbon $ref = null): string
    {
        $ref = $ref ?? now(config('app.timezone_local'));
        $today = $ref->copy()->startOfDay();

        $current = $this->currentPeriodFor($frequency, $anchor, $payDelay, $ref);
        if (Carbon::parse($current['pay_date'])->gte($today)) {
            return $current['pay_date'];
        }

        // This period's pay date already passed → roll to the next period.
        $nextRef = Carbon::parse($current['end'])->addDay();

        return $this->currentPeriodFor($frequency, $anchor, $payDelay, $nextRef)['pay_date'];
    }

    /**
     * @return array{0: Carbon, 1: Carbon} [start, end]
     */
    private function periodBounds(string $frequency, ?Carbon $anchor, Carbon $ref): array
    {
        switch ($frequency) {
            case 'monthly':
                return [$ref->copy()->startOfMonth(), $ref->copy()->endOfMonth()];

            case 'weekly':
            case 'bi_weekly':
                $len = $frequency === 'weekly' ? 7 : 14;
                $anchorDay = $anchor ? $anchor->copy()->startOfDay() : $ref->copy()->startOfWeek(Carbon::MONDAY);
                $offset = (int) floor($anchorDay->diffInDays($ref, false) / $len);
                $start = $anchorDay->copy()->addDays($offset * $len);

                return [$start, $start->copy()->addDays($len - 1)];

            case 'semi_monthly':
            default:
                if ($ref->day <= 15) {
                    return [$ref->copy()->startOfMonth(), $ref->copy()->day(15)];
                }

                return [$ref->copy()->day(16), $ref->copy()->endOfMonth()];
        }
    }

    /**
     * Where a period sits among its calendar month's periods (by period end date):
     * returns [index (0-based), count]. Drives "first / last cutoff" deduction timing.
     *
     * @return array{0: int, 1: int}
     */
    public function monthPosition(string $frequency, ?Carbon $anchor, string $start, string $end): array
    {
        $monthRef = Carbon::parse($end);
        $periods = $this->monthPeriods($frequency, $anchor, $monthRef);
        $count = max(1, count($periods));
        $index = 0;
        foreach ($periods as $i => [$s]) {
            if ($s->toDateString() === $start) {
                $index = $i;
                break;
            }
        }

        return [$index, $count];
    }

    /**
     * All periods whose END date falls in `monthRef`'s calendar month, ordered.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function monthPeriods(string $frequency, ?Carbon $anchor, Carbon $monthRef): array
    {
        $monthEnd = $monthRef->copy()->endOfMonth();
        [$s, $e] = $this->periodBounds($frequency, $anchor, $monthRef->copy()->startOfMonth());

        $periods = [];
        $guard = 0;
        while ($s->lte($monthEnd) && $guard++ < 60) {
            if ($e->month === $monthRef->month && $e->year === $monthRef->year) {
                $periods[] = [$s->copy(), $e->copy()];
            }
            [$s, $e] = $this->periodBounds($frequency, $anchor, $e->copy()->addDay());
        }

        return $periods;
    }

    /**
     * Does (start, end) exactly match a real pay period for this frequency? Used to
     * reject arbitrary/misaligned cycle dates before a run.
     */
    public function isAlignedPeriod(string $frequency, ?Carbon $anchor, string $start, string $end): bool
    {
        try {
            [$s, $e] = $this->periodBounds($frequency, $anchor, Carbon::parse($start));
        } catch (\Throwable) {
            return false;
        }

        return $s->toDateString() === $start && $e->toDateString() === $end;
    }

    /** Count Mon–Fri days in an inclusive date range (for the daily rate / proration). */
    /**
     * How many employees have NO explicit `pay_frequency` and therefore follow the company
     * default.
     */
    public function inheritingDefaultCount(): int
    {
        return Employee::query()
            ->where(fn ($q) => $q->whereNull('pay_frequency')->orWhere('pay_frequency', ''))
            ->count();
    }

    /**
     * The active pay groups: one per distinct effective frequency among employees
     * (employee.pay_frequency, falling back to the company default), each with its
     * headcount and current period.
     *
     * @return array<int, array<string, mixed>>
     */
    public function payGroups(PayrollSetting $setting): array
    {
        $default = $setting->frequency;
        $counts = Employee::query()
            ->selectRaw('COALESCE(NULLIF(pay_frequency, ""), ?) as freq, COUNT(*) as c', [$default])
            ->groupBy('freq')
            ->pluck('c', 'freq');

        $groups = [];
        foreach (array_keys(self::PERIODS_PER_YEAR) as $freq) {
            $count = (int) ($counts[$freq] ?? 0);
            if ($count === 0) {
                continue;
            }
            $groups[] = [
                'frequency' => $freq,
                'employee_count' => $count,
                'periods_per_year' => self::periodsPerYear($freq),
                'current_period' => $this->currentPeriodFor($freq, $setting->anchor_date, $setting->pay_delay_days ?? 0),
            ];
        }

        return $groups;
    }
}
