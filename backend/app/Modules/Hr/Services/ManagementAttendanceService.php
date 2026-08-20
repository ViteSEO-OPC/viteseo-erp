<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Generates the attendance rows for management staff. */
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
     * @param  array<string, mixed>  $eff
     */
    private function fill(Attendance $row, Carbon $localDay, array $eff): void
    {
        $tz = config('app.timezone_local');

        $timeIn = Carbon::parse("{$localDay->toDateString()} {$eff['start_time']}", $tz);
        $timeOut = Carbon::parse("{$localDay->toDateString()} {$eff['end_time']}", $tz);
        if ($timeOut->lt($timeIn)) {
            $timeOut->addDay(); // overnight schedule ends on the following morning
        }

        $row->time_in = $timeIn->utc();
        $row->time_out = $timeOut->utc();
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
