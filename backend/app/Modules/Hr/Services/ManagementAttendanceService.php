<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Generates the attendance rows for management staff.
 *
 * Management is exempt from the time clock — they never scan in or out. Until now
 * that meant they had NO attendance rows at all, and every consumer of attendance
 * had to special-case them: the roster does, payroll does, and the violation engine
 * did not — so management accumulated an unbroken AWOL streak for every past
 * workday since their hire date.
 *
 * Rather than add an nth `isManagement()` guard, we write the rows the exempt
 * arrangement implies: on each scheduled workday, present for their full shift.
 * Every present and future consumer of attendance is then correct for free.
 *
 * The rows are marked `source = 'system'` so an audit or a payroll dispute can
 * always tell system-generated presence from a human's attestation. Nothing else
 * in the app writes that value, and the violation engine deliberately ignores
 * these rows when it works out when the org started tracking attendance.
 *
 * Rules, in order:
 *   - Only `is_management` employees (soft-deleted employees are out of scope).
 *   - Never before the employee's hire date.
 *   - Only days their EFFECTIVE schedule says are workdays (override → assigned →
 *     default), so a management member on a Tue–Sat schedule gets Tue–Sat.
 *   - Never on a day covered by an approved leave request — leave is the truth there.
 *   - Never overwrite a human: an existing row that is not `source = 'system'` is
 *     left exactly as it is, and so is a soft-deleted row (deleting was deliberate).
 *   - Idempotent: re-running refreshes only the rows this job owns.
 */
class ManagementAttendanceService
{
    public function __construct(
        private readonly ScheduleService $schedule,
    ) {}

    /**
     * Generate/refresh management rows across an inclusive Manila date range.
     *
     * @return array{created:int, updated:int, skipped:int, employees:int, notes:array<int, string>}
     */
    public function generate(string $from, string $to, bool $dryRun = false): array
    {
        $tz = config('app.timezone_local');
        $start = Carbon::parse($from, $tz)->startOfDay();
        $end = Carbon::parse($to, $tz)->startOfDay();

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'employees' => 0, 'notes' => []];

        if ($end->lt($start)) {
            $stats['notes'][] = "Empty range: {$from} is after {$to}.";

            return $stats;
        }

        $employees = Employee::with('workSchedule')->where('is_management', true)->get();
        $stats['employees'] = $employees->count();

        if ($employees->isEmpty()) {
            return $stats;
        }

        // Approved leave overlapping the range, grouped by employee — one query, not one per day.
        $leaves = LeaveRequest::where('status', 'approved')
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('end_date', '>=', $start->toDateString())
            ->whereDate('start_date', '<=', $end->toDateString())
            ->get(['employee_id', 'start_date', 'end_date'])
            ->groupBy('employee_id');

        foreach ($employees as $emp) {
            $hireDate = $emp->hire_date?->toDateString();
            $empLeaves = $leaves->get($emp->id);

            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $dateStr = $d->toDateString();

                if ($hireDate && $dateStr < $hireDate) {
                    $stats['skipped']++;

                    continue;
                }

                $eff = $this->schedule->effectiveFor($emp, $d);

                if (! $eff['is_workday'] || $this->onLeave($empLeaves, $dateStr)) {
                    $stats['skipped']++;

                    continue;
                }

                if ($eff['start_time'] === null || $eff['end_time'] === null || $eff['expected_minutes'] === null) {
                    $stats['skipped']++;
                    $stats['notes'][] = "Employee #{$emp->id} has no usable shift window on {$dateStr} — skipped.";

                    continue;
                }

                // withTrashed: a soft-deleted row still holds the (employee, work_date)
                // unique key, so inserting over it would fail. A deleted row is also a
                // deliberate act — leave it deleted.
                $existing = Attendance::withTrashed()
                    ->where('employee_id', $emp->id)
                    ->where('work_date', $dateStr)
                    ->first();

                if ($existing && ($existing->trashed() || ! $existing->isSystemGenerated())) {
                    $stats['skipped']++;

                    continue;
                }

                $row = $existing ?? new Attendance([
                    'employee_id' => $emp->id,
                    'work_date' => $dateStr,
                ]);

                $this->fill($row, $d, $eff);

                if (! $dryRun) {
                    $row->save();
                }

                $stats[$existing ? 'updated' : 'created']++;
            }
        }

        return $stats;
    }

    /**
     * Populate a row from the employee's effective schedule for the day.
     *
     * Times are built as Manila wall-clock and converted to UTC before storing —
     * Eloquent writes the Carbon's own wall-clock into the column without
     * converting, so a Manila-tz Carbon would land 8 hours off.
     *
     * Hours mirror the schedule's paid day (`expected_minutes` = full span minus the
     * UNPAID meal period). The paid break is paid by definition, so it is not
     * deducted — which keeps `gross - lunch_minutes - break_minutes` equal to
     * `hours_worked`, the same identity a real clock-out produces.
     *
     * @param  array<string, mixed>  $eff
     */
    private function fill(Attendance $row, Carbon $localDay, array $eff): void
    {
        $tz = config('app.timezone_local');

        $row->time_in = Carbon::parse("{$localDay->toDateString()} {$eff['start_time']}", $tz)->utc();
        $row->time_out = Carbon::parse("{$localDay->toDateString()} {$eff['end_time']}", $tz)->utc();
        $row->lunch_minutes = (int) $eff['unpaid_break_minutes'];
        $row->break_minutes = 0;
        $row->hours_worked = round(((int) $eff['expected_minutes']) / 60, 2);
        // Management is exempt from the clock, so they are never 'late' — the arrival
        // category is meaningless for a shift that was never actually scanned.
        $row->status = 'present';
        $row->source = Attendance::SOURCE_SYSTEM;
    }

    /**
     * Does an approved leave cover this date?
     *
     * @param  Collection<int, LeaveRequest>|null  $intervals
     */
    private function onLeave($intervals, string $date): bool
    {
        if (! $intervals) {
            return false;
        }

        foreach ($intervals as $leave) {
            if ($leave->start_date->toDateString() <= $date && $leave->end_date->toDateString() >= $date) {
                return true;
            }
        }

        return false;
    }
}
