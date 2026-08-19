<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\LeaveRequest;
use Illuminate\Support\Carbon;

/**
 * Which half of a day an employee is off — and therefore which window they were actually
 * expected to work.
 *
 * WHY THIS EXISTS. Exactly one function in the module used to know that an approved half day
 * shrinks the working window: DeductionService::workedWindow(), which prices per-minute late
 * and undertime. Everything else — the clock-in status, the roster's punctuality badge, the
 * open-shift auto-close — measured against the FULL 08:00–17:00 day. So an employee with an
 * approved morning off who clocked in at 1:00 PM was stamped `late` by five hours. That status
 * is not cosmetic: it raises a disciplinary violation, and with the per-N-lates penalty switched
 * on it costs a full day's pay for arriving exactly on time.
 *
 * TWO SOURCES, ONE ANSWER. A half day arrives either as an approved HalfDayRequest (always
 * unpaid; `period` names the half taken OFF) or as an approved half-day LeaveRequest
 * (`half_day_period`, same meaning, funded by a credit instead). They are the same shape with
 * different funding, so nothing about lateness may depend on which one it was.
 *
 * BOTH HALVES OFF is a real state, not a nonsense one — AM and PM are filed separately and
 * payroll already clamps the pair to one full day. It is not the same as "no half day": the
 * whole day is off, so there is no start to be late for at all.
 *
 * READS ARE BY DATE RANGE, NEVER BY EMPLOYEE. Two queries fetch every approved half day in the
 * range for the whole company, memoized under the range alone, and callers index into the result.
 * Narrowing the query to one employee would be the obvious thing and the wrong one: payroll walks
 * employees in a loop and asks four separate questions per employee, so a per-employee key turns
 * into 2N queries and `PayrollPerformanceTest` — a ratchet against exactly this — fails. Half days
 * are sparse, so the whole-company result for one cycle is a handful of rows.
 *
 * Any write that changes an approval must call forget(), the same discipline
 * AttendanceService::forgetRows() already follows for its own row memo.
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
     * The approval filters MIRROR PAYROLL EXACTLY (see AttendanceService::unpaidDaysDetailFor,
     * which counts the same two tables the same way). A row this service treats as a half day
     * but payroll does not — or the reverse — would put the roster badge and the payslip back
     * into disagreement, which is the whole failure being fixed here.
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
     * Mirrors DeductionService::workedWindow() deliberately — same precedence, same fallback —
     * because the badge and the payslip disagreeing about which minutes were owed is exactly the
     * bug this service exists to end. THE FALLBACK MATTERS: a company that never filled in the
     * AM/PM columns keeps its full-day window, so this stays a no-op for them instead of silently
     * narrowing the day to times nobody configured.
     *
     * @param  array<string, mixed>  $eff  the employee's effective schedule for the date
     * @return array{0: ?string, 1: ?string}  ['HH:MM' start, 'HH:MM' end]; [null, null] whole day off
     */
    public function windowFor(array $eff, ?string $offHalf): array
    {
        if ($offHalf === self::BOTH) {
            return [null, null];
        }

        if ($offHalf === self::AM && isset($eff['pm_start_time'], $eff['pm_end_time'])) {
            return [$eff['pm_start_time'], $eff['pm_end_time']];
        }

        if ($offHalf === self::PM && isset($eff['am_start_time'], $eff['am_end_time'])) {
            return [$eff['am_start_time'], $eff['am_end_time']];
        }

        return [$eff['start_time'] ?? null, $eff['end_time'] ?? null];
    }

    /**
     * Like windowFor(), except a whole day off keeps the full schedule window.
     *
     * For the places where a null window would leave a day UNPRICEABLE rather than merely
     * unjudged. The open-shift resolver needs an end to auto-close an abandoned punch against;
     * refusing to derive one would strand a day that pays perfectly well in HR's exception queue.
     * Somebody who punched in on a day they had entirely off is a question for a human — not a
     * reason to drop the record on the floor.
     *
     * @param  array<string, mixed>  $eff
     * @return array{0: ?string, 1: ?string}
     */
    public function pricingWindowFor(array $eff, ?string $offHalf): array
    {
        return $offHalf === self::BOTH
            ? [$eff['start_time'] ?? null, $eff['end_time'] ?? null]
            : $this->windowFor($eff, $offHalf);
    }
}
