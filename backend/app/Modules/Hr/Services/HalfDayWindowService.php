<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\LeaveRequest;
use Illuminate\Support\Carbon;

/**
 * Which half of a day an employee is off — and therefore which window they were actually
 * expected to work.
 */
class HalfDayWindowService
{
    public const AM = 'AM';

    public const PM = 'PM';

    /** Both halves filed off — the whole day, reached by two separate requests. */
    public const BOTH = 'BOTH';

    /**
     * Per-instance memo of batch lookups, keyed by the exact query arguments.
     *
     * @var array<string, array<string, string>>
     */
    private array $memo = [];

    /** Drop the memo after an approval changes, so a decision is never read stale. */
    public function forget(): void
    {
        $this->memo = [];
    }

    /**
     * Every approved half day in an inclusive date range, for every employee.
     *
     * @return array<string, string>  "employeeId|Y-m-d" => 'AM' | 'PM' | 'BOTH'
     */
    public function offHalvesBetween(string $start, string $end): array
    {
        $key = "{$start}|{$end}";
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        /** @var array<string, array<string, true>> $halves */
        $halves = [];

        $requests = HalfDayRequest::query()
            ->where('status', 'approved')
            ->whereBetween('work_date', [$start, $end])
            ->get(['employee_id', 'work_date', 'period']);

        foreach ($requests as $request) {
            $dayKey = $request->employee_id . '|' . $request->work_date->toDateString();
            $halves[$dayKey][strtoupper((string) $request->period)] = true;
        }

        $leaves = LeaveRequest::query()
            ->where('status', 'approved')
            ->where('is_half_day', true)
            ->whereNotNull('half_day_period')
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get(['employee_id', 'start_date', 'end_date', 'half_day_period']);

        foreach ($leaves as $leave) {
            // A half-day leave is single-day by construction — LeaveService::computeDays() refuses
            // any other shape — but the range is walked rather than trusted, so a row that predates
            // that rule (or arrived by direct insert) still lands on the days it actually covers.
            $from = max(Carbon::parse($leave->start_date)->toDateString(), $start);
            $to = min(Carbon::parse($leave->end_date)->toDateString(), $end);
            $period = strtoupper((string) $leave->half_day_period);

            for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
                $halves[$leave->employee_id . '|' . $d->toDateString()][$period] = true;
            }
        }

        $out = [];
        foreach ($halves as $dayKey => $periods) {
            // Two DIFFERENT halves make a whole day off; the same half filed twice (a leave and a
            // request for the same morning) is still just that morning, which is why this counts
            // distinct periods rather than rows.
            $out[$dayKey] = count($periods) > 1 ? self::BOTH : (string) array_key_first($periods);
        }

        return $this->memo[$key] = $out;
    }

    /** One employee, one date — for the write paths that only ever touch a single day. */
    public function offHalfFor(int $employeeId, string $date): ?string
    {
        return $this->offHalvesBetween($date, $date)["{$employeeId}|{$date}"] ?? null;
    }

    /**
     * The window an employee was expected to work on a day whose off-half is $offHalf.
     *
     * @param  array<string, mixed>  $eff  the employee's effective schedule for the date
     * @return array{0: ?string, 1: ?string}  ['HH:MM' start, 'HH:MM' end]; [null, null] when there
     *                                        is no window to judge: the whole day off, or a half
     *                                        day whose worked half is unconfigured
     */
    public function windowFor(array $eff, ?string $offHalf): array
    {
        if ($offHalf === self::BOTH) {
            return [null, null];
        }

        if ($offHalf === self::AM) {
            return isset($eff['pm_start_time'], $eff['pm_end_time'])
                ? [$eff['pm_start_time'], $eff['pm_end_time']]
                : [null, null];
        }

        if ($offHalf === self::PM) {
            return isset($eff['am_start_time'], $eff['am_end_time'])
                ? [$eff['am_start_time'], $eff['am_end_time']]
                : [null, null];
        }

        return [$eff['start_time'] ?? null, $eff['end_time'] ?? null];
    }

    /** Does this day have a usable window for the half the employee is expected to work? */
    public function hasConfiguredWindow(array $eff, ?string $offHalf): bool
    {
        return $this->windowFor($eff, $offHalf)[0] !== null;
    }

    /**
     * Like windowFor(), except a whole day off keeps the full schedule window.
     *
     * @param  array<string, mixed>  $eff
     * @return array{0: ?string, 1: ?string}
     */
    public function pricingWindowFor(array $eff, ?string $offHalf): array
    {
        [$start, $end] = $this->windowFor($eff, $offHalf);

        return $start === null
            ? [$eff['start_time'] ?? null, $eff['end_time'] ?? null]
            : [$start, $end];
    }
}
