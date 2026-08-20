<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveSetting;
use App\Modules\Hr\Models\SharedLeaveBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The three month-scoped attendance reports behind the Reports hub's Attendance Summary
 * screen: per-employee totals, the daily time record, and leave usage.
 */
class AttendanceReportService
{
    /** The report keys this service answers, in tab order. */
    public const KEYS = ['attendance_summary', 'attendance_dtr', 'attendance_leave'];

    /** How many months the month picker offers, ending at the current one. */
    private const MONTH_CHOICES = 12;

    public function __construct(
        private readonly ScheduleService $schedule,
        private readonly HolidayService $holidays,
    ) {}

    /** Shared tab metadata, so the three reports describe each other identically. */
    public static function tabs(): array
    {
        return [
            ['key' => 'attendance_summary', 'title' => 'Monthly attendance summary', 'description' => 'Per employee attendance totals for a month'],
            ['key' => 'attendance_dtr', 'title' => 'Daily time record', 'description' => 'Time in and out logs per employee'],
            ['key' => 'attendance_leave', 'title' => 'Leave report', 'description' => 'Leave usage and remaining balances'],
        ];
    }

    public static function titleFor(string $key): string
    {
        return match ($key) {
            'attendance_summary' => 'Attendance Summary',
            'attendance_dtr' => 'Daily Time Record',
            'attendance_leave' => 'Leave Report',
            default => 'Attendance',
        };
    }

    // ---------------------------------------------------------------- reports

    /**
     * Monthly attendance summary — one row per employee with present / late / absent /
     * on-leave day counts and an attendance rate, each expandable to the day-by-day grid.
     *
     * @param  array<string, mixed>  $filters
     */
    public function summary(array $filters = []): array
    {
        $ctx = $this->context($filters);

        $rows = [];
        $totals = ['present' => 0, 'late' => 0, 'absent' => 0, 'on_leave' => 0, 'attended' => 0, 'expected' => 0];

        foreach ($ctx['employees'] as $employee) {
            $grid = $this->gridFor($employee, $ctx);

            foreach (['present', 'late', 'absent', 'on_leave', 'attended', 'expected'] as $k) {
                $totals[$k] += $grid[$k];
            }

            $rows[] = [
                'employee_id' => $employee->id,
                'name' => $employee->user?->name ?? 'Unknown',
                'department' => $employee->department?->name,
                'present' => $grid['present'],
                'late' => $grid['late'],
                'absent' => $grid['absent'],
                'on_leave' => $grid['on_leave'],
                'expected_days' => $grid['expected'],
                'rate' => $grid['rate'],
                'days' => $grid['days'],
            ];
        }

        // The headline rate is one company-wide ratio, NOT the mean of the per-employee rates: a
        // person who was employed for three days of the month would otherwise weigh as heavily as
        // one who worked all 23.
        $averageRate = $totals['expected'] > 0
            ? round($totals['attended'] / $totals['expected'] * 100, 1)
            : null;

        return $this->envelope('attendance_summary', $ctx, [
            'description' => 'Monthly individual attendance summary per employee, covering present days, absences, tardiness, and days taken as leave.',
            'summary' => [
                ['label' => 'Working days', 'value' => (string) $ctx['working_days']],
                ['label' => 'Employees', 'value' => (string) count($rows)],
                ['label' => 'Average attendance', 'value' => $averageRate === null ? '—' : number_format($averageRate, 1) . '%'],
                ['label' => 'Total late', 'value' => (string) $totals['late']],
                ['label' => 'Total absences', 'value' => (string) $totals['absent']],
            ],
            'attendance' => $rows,
            'table' => [
                'columns' => ['Employee', 'Department', 'Present', 'Late', 'Absent', 'On leave', 'Attendance rate'],
                'rows' => array_map(fn ($r) => [
                    $r['name'],
                    $r['department'] ?? '—',
                    (string) $r['present'],
                    (string) $r['late'],
                    (string) $r['absent'],
                    (string) $r['on_leave'],
                    $r['rate'] === null ? '—' : $r['rate'] . '%',
                ], $rows),
            ],
        ]);
    }

    /**
     * Daily time record — the punch log. One row per employee summarising the month, expanding
     * to every day with its in/out times and hours. This is the artifact HR prints for an audit,
     * so the CSV is deliberately one line per employee-day rather than per employee.
     *
     * @param  array<string, mixed>  $filters
     */
    public function dailyTimeRecord(array $filters = []): array
    {
        $ctx = $this->context($filters);

        $rows = [];
        $csv = [];
        $totalHours = 0.0;
        $totalLogged = 0;

        foreach ($ctx['employees'] as $employee) {
            $grid = $this->gridFor($employee, $ctx);

            $logged = array_values(array_filter($grid['days'], fn ($d) => $d['time_in'] !== null));
            $hours = array_sum(array_column($grid['days'], 'hours'));
            $totalHours += $hours;
            $totalLogged += count($logged);

            $rows[] = [
                'employee_id' => $employee->id,
                'name' => $employee->user?->name ?? 'Unknown',
                'department' => $employee->department?->name,
                'days_logged' => count($logged),
                'total_hours' => round($hours, 2),
                'average_hours' => $logged === [] ? null : round($hours / count($logged), 2),
                'average_in' => $this->averageClock(array_column($logged, 'in_minutes')),
                'average_out' => $this->averageClock(array_column($logged, 'out_minutes')),
                'days' => $grid['days'],
            ];

            foreach ($grid['days'] as $day) {
                // Days nobody was expected in (rest days, holidays, before hire) would pad the file
                // with blank lines; a day that was expected and has no punch is the interesting one.
                if ($day['time_in'] === null && ! in_array($day['status'], ['absent', 'on_leave', 'half_day'], true)) {
                    continue;
                }

                $csv[] = [
                    $employee->user?->name ?? 'Unknown',
                    $employee->department?->name ?? '—',
                    $day['date'],
                    $day['weekday'],
                    $day['time_in'] ?? '—',
                    $day['time_out'] ?? '—',
                    $day['hours'] > 0 ? number_format($day['hours'], 2) : '—',
                    $this->statusLabel($day['status']),
                ];
            }
        }

        return $this->envelope('attendance_dtr', $ctx, [
            'description' => 'Time in and time out logs per employee for the month, with hours worked and the status of every day.',
            'summary' => [
                ['label' => 'Working days', 'value' => (string) $ctx['working_days']],
                ['label' => 'Employees', 'value' => (string) count($rows)],
                ['label' => 'Days logged', 'value' => (string) $totalLogged],
                ['label' => 'Hours logged', 'value' => number_format($totalHours, 1)],
                ['label' => 'Average hours / day', 'value' => $totalLogged > 0 ? number_format($totalHours / $totalLogged, 2) : '—'],
            ],
            'dtr' => $rows,
            'table' => [
                'columns' => ['Employee', 'Department', 'Date', 'Day', 'Time in', 'Time out', 'Hours', 'Status'],
                'rows' => $csv,
            ],
        ]);
    }

    /**
     * Leave report — days taken inside the month against the balance that remains for the year.
     *
     * @param  array<string, mixed>  $filters
     */
    public function leaveReport(array $filters = []): array
    {
        $ctx = $this->context($filters);
        $year = (int) substr($ctx['month'], 0, 4);
        $isShared = LeaveSetting::current()->allotment_mode === LeaveSetting::MODE_SHARED;

        $employeeIds = $ctx['employees']->pluck('id')->all();
        $balances = $this->balancesFor($employeeIds, $year, $isShared);
        $requests = $this->requestsIn($employeeIds, $ctx['start'], $ctx['end']);

        $rows = [];
        $totals = ['taken' => 0.0, 'approved' => 0, 'pending' => 0, 'remaining' => 0.0];

        foreach ($ctx['employees'] as $employee) {
            $grid = $this->gridFor($employee, $ctx);
            $mine = $requests->get($employee->id, collect());
            $balance = $balances[$employee->id] ?? ['entitled' => 0.0, 'used' => 0.0, 'types' => []];

            $approved = $mine->where('status', 'approved')->count();
            $pending = $mine->where('status', 'pending')->count();
            // Days taken comes off the day grid, not off `days` on the request: a request that
            // straddles a month boundary must only count the part that lands inside this month.
            $taken = (float) $grid['on_leave'];
            $remaining = max(0.0, $balance['entitled'] - $balance['used']);

            $totals['taken'] += $taken;
            $totals['approved'] += $approved;
            $totals['pending'] += $pending;
            $totals['remaining'] += $remaining;

            $rows[] = [
                'employee_id' => $employee->id,
                'name' => $employee->user?->name ?? 'Unknown',
                'department' => $employee->department?->name,
                'taken' => $taken,
                'approved' => $approved,
                'pending' => $pending,
                'entitled' => round($balance['entitled'], 1),
                'used' => round($balance['used'], 1),
                'remaining' => round($remaining, 1),
                'balances' => $balance['types'],
                'requests' => $mine->map(fn (LeaveRequest $r) => [
                    'id' => $r->id,
                    'type' => $r->leaveType?->name ?? 'Leave',
                    'start_date' => $r->start_date?->toDateString(),
                    'end_date' => $r->end_date?->toDateString(),
                    'days' => (float) $r->days,
                    'status' => $r->status,
                ])->values()->all(),
            ];
        }

        return $this->envelope('attendance_leave', $ctx, [
            'description' => 'Leave taken during the month against each employee’s remaining balance for the year.',
            'summary' => [
                ['label' => 'Employees', 'value' => (string) count($rows)],
                ['label' => 'Leave days taken', 'value' => number_format($totals['taken'], 1)],
                ['label' => 'Approved requests', 'value' => (string) $totals['approved']],
                ['label' => 'Pending requests', 'value' => (string) $totals['pending']],
                ['label' => "Remaining days ({$year})", 'value' => number_format($totals['remaining'], 1)],
            ],
            'leave' => $rows,
            'note' => 'Days taken cover ' . $ctx['month_label'] . ' only; entitlement and remaining balance are for the '
                . $year . ' leave year' . ($isShared ? ' and read from the shared pool.' : ', summed across leave types.'),
            'table' => [
                'columns' => ['Employee', 'Department', 'Days taken', 'Approved requests', 'Pending requests', 'Entitled', 'Used', 'Remaining'],
                'rows' => array_map(fn ($r) => [
                    $r['name'],
                    $r['department'] ?? '—',
                    number_format($r['taken'], 1),
                    (string) $r['approved'],
                    (string) $r['pending'],
                    number_format($r['entitled'], 1),
                    number_format($r['used'], 1),
                    number_format($r['remaining'], 1),
                ], $rows),
            ],
        ]);
    }

    // --------------------------------------------------------------- envelope

    /**
     * The fields every one of the three reports carries: title, the filter options the screen
     * needs to render its own controls, and the period it actually resolved to.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $report
     */
    private function envelope(string $key, array $ctx, array $report): array
    {
        return array_merge([
            'title' => self::titleFor($key),
            'mock' => false,
            'bars' => null,
            'table' => null,
            'note' => null,
            'tabs' => self::tabs(),
            'active_tab' => $key,
            'months' => $ctx['months'],
            'selected_month' => ['value' => $ctx['month'], 'label' => $ctx['month_label']],
            'departments' => $ctx['departments'],
            'selected_department' => $ctx['department_label'],
            'working_days' => $ctx['working_days'],
            'elapsed_working_days' => $ctx['elapsed_working_days'],
            'month_in_progress' => $ctx['month_in_progress'],
            'generated_at' => now(config('app.timezone_local'))->format('M j, Y g:i A'),
        ], $report);
    }

    // ---------------------------------------------------------------- context

    /**
     * Everything the three builders share for one month: the resolved period, the employees in
     * scope, and the calendar facts (holidays, attendance rows, leave dates) fetched ONCE for the
     * whole set rather than per employee.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function context(array $filters): array
    {
        $tz = config('app.timezone_local');
        $today = now($tz)->startOfDay();

        $month = $this->resolveMonth($filters['month'] ?? null, $today);
        $first = Carbon::createFromFormat('Y-m-d', $month . '-01', $tz)->startOfDay();
        $last = $first->copy()->endOfMonth();
        $start = $first->toDateString();
        $end = $last->toDateString();

        $departments = Department::orderBy('name')->get(['id', 'name'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name])
            ->all();

        $deptId = $this->resolveDepartment($filters['department'] ?? null, $departments);
        $employees = $this->employeesFor($deptId, $start, $end);
        $employeeIds = $employees->pluck('id')->all();

        // Non-working holidays only: a `special_working` day is an ordinary working day and must
        // keep counting as one (mirrors AttendanceService::nonWorkingHolidays()).
        $holidays = array_filter(
            $this->holidays->typedHolidaysBetween($first, $last),
            fn ($type) => in_array($type, HolidayService::NON_WORKING_TYPES, true),
        );

        $defaultWorkdays = array_map('intval', $this->schedule->default()?->workdays ?? [1, 2, 3, 4, 5]);
        $workingDays = 0;
        $elapsed = 0;
        for ($d = $first->copy(); $d->lte($last); $d->addDay()) {
            if (! in_array($d->isoWeekday(), $defaultWorkdays, true) || isset($holidays[$d->toDateString()])) {
                continue;
            }
            $workingDays++;
            if ($d->lt($today)) {
                $elapsed++;
            }
        }

        return [
            'month' => $month,
            'month_label' => $first->format('F Y'),
            'start' => $start,
            'end' => $end,
            'today' => $today->toDateString(),
            'days_in_month' => $last->day,
            'employees' => $employees,
            'attendance' => $this->attendanceFor($employeeIds, $start, $end),
            'leave' => $this->leaveDatesFor($employeeIds, $start, $end),
            'holidays' => $holidays,
            'departments' => $departments,
            'department_label' => $deptId === null
                ? 'All departments'
                : (collect($departments)->firstWhere('id', $deptId)['name'] ?? 'All departments'),
            'months' => $this->monthOptions($month, $today, $tz),
            'working_days' => $workingDays,
            'elapsed_working_days' => $elapsed,
            'month_in_progress' => $last->gte($today),
            'timezone' => $tz,
        ];
    }

    /** A `YYYY-MM` filter, or the current month when it is missing or malformed. */
    private function resolveMonth(mixed $month, Carbon $today): string
    {
        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1
            ? $month
            : $today->format('Y-m');
    }

    /** @param array<int, array{id:int, name:string}> $departments */
    private function resolveDepartment(mixed $department, array $departments): ?int
    {
        if (! is_numeric($department)) {
            return null;
        }

        $id = (int) $department;

        return collect($departments)->contains('id', $id) ? $id : null;
    }

    /**
     * The last twelve months ending at the current one, plus the selected month when it falls
     * outside that window — a report linked to from an email must still be able to show itself.
     *
     * @return array<int, array{value:string, label:string}>
     */
    private function monthOptions(string $selected, Carbon $today, string $tz): array
    {
        $options = [];
        $cursor = $today->copy()->startOfMonth();

        for ($i = 0; $i < self::MONTH_CHOICES; $i++) {
            $options[] = ['value' => $cursor->format('Y-m'), 'label' => $cursor->format('F Y')];
            $cursor->subMonth();
        }

        if (! collect($options)->contains('value', $selected)) {
            array_unshift($options, [
                'value' => $selected,
                'label' => Carbon::createFromFormat('Y-m-d', $selected . '-01', $tz)->format('F Y'),
            ]);
        }

        return $options;
    }

    /**
     * Employees whose employment overlaps the month, so a historical month still shows the people
     * who worked it. Separation soft-deletes the record, hence `withTrashed()`; a trashed record
     * is kept only when the separation (or the deletion itself) happened on or after the period
     * started.
     *
     * @return Collection<int, Employee>
     */
    private function employeesFor(?int $deptId, string $start, string $end): Collection
    {
        return Employee::withTrashed()
            ->with(['user', 'department', 'workSchedule'])
            ->when($deptId !== null, fn ($q) => $q->where('department_id', $deptId))
            ->where(fn ($q) => $q->whereNull('hire_date')->orWhere('hire_date', '<=', $end))
            ->where(fn ($q) => $q
                ->whereNull('deleted_at')
                ->orWhere('separation_date', '>=', $start)
                ->orWhere('deleted_at', '>=', $start . ' 00:00:00'))
            ->get()
            ->sortBy(fn (Employee $e) => $e->user?->name ?? '')
            ->values();
    }

    /**
     * Attendance rows for the whole set, as employee_id => (date => row).
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array<string, Attendance>>
     */
    private function attendanceFor(array $employeeIds, string $start, string $end): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $out = [];
        foreach (Attendance::whereIn('employee_id', $employeeIds)->whereBetween('work_date', [$start, $end])->get() as $row) {
            $out[$row->employee_id][Carbon::parse($row->work_date)->toDateString()] = $row;
        }

        return $out;
    }

    /**
     * Dates covered by an APPROVED leave request, as employee_id => (date => leave type name).
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array<string, string>>
     */
    private function leaveDatesFor(array $employeeIds, string $start, string $end): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $out = [];

        $requests = LeaveRequest::with('leaveType')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get();

        foreach ($requests as $request) {
            $from = $request->start_date?->toDateString();
            $to = $request->end_date?->toDateString();
            if ($from === null || $to === null) {
                continue;
            }

            $cursor = Carbon::parse(max($from, $start));
            $stop = Carbon::parse(min($to, $end));

            for (; $cursor->lte($stop); $cursor->addDay()) {
                $out[$request->employee_id][$cursor->toDateString()] = $request->leaveType?->name ?? 'Leave';
            }
        }

        return $out;
    }

    /**
     * Leave requests overlapping the month, keyed by employee.
     *
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, Collection<int, LeaveRequest>>
     */
    private function requestsIn(array $employeeIds, string $start, string $end): Collection
    {
        if ($employeeIds === []) {
            return collect();
        }

        return LeaveRequest::with('leaveType')
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', ['approved', 'pending'])
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->orderBy('start_date')
            ->get()
            ->groupBy('employee_id');
    }

    /**
     * Leave entitlement and usage per employee for a year, read from whichever bucket the
     * allotment mode makes authoritative. Under shared mode the per-type rows keep a `used_days`
     * of 0, so summing them would report every employee as having taken nothing.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array{entitled:float, used:float, types:array<int, array{name:string, entitled:float, used:float, remaining:float}>}>
     */
    private function balancesFor(array $employeeIds, int $year, bool $isShared): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $out = [];

        if ($isShared) {
            foreach (SharedLeaveBalance::whereIn('employee_id', $employeeIds)->where('year', $year)->get() as $b) {
                $out[$b->employee_id] = [
                    'entitled' => (float) $b->entitled_days,
                    'used' => (float) $b->used_days,
                    'types' => [],
                ];
            }

            return $out;
        }

        $balances = LeaveBalance::with('leaveType')
            ->whereIn('employee_id', $employeeIds)
            ->where('year', $year)
            ->get();

        foreach ($balances as $b) {
            // Carried-over days are spendable, so they are part of the entitlement the remaining
            // figure is measured against — leaving them out understates what the employee has.
            $entitled = (float) $b->entitled_days + (float) $b->carried_days;
            $used = (float) $b->used_days;

            $out[$b->employee_id] ??= ['entitled' => 0.0, 'used' => 0.0, 'types' => []];
            $out[$b->employee_id]['entitled'] += $entitled;
            $out[$b->employee_id]['used'] += $used;
            $out[$b->employee_id]['types'][] = [
                'name' => $b->leaveType?->name ?? 'Leave',
                'entitled' => round($entitled, 1),
                'used' => round($used, 1),
                'remaining' => round(max(0.0, $entitled - $used), 1),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------- day grid

    /**
     * One employee's month, day by day, plus the counts every report reads off it.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function gridFor(Employee $employee, array $ctx): array
    {
        $tz = $ctx['timezone'];
        $eff = $this->schedule->effectiveFor($employee);
        $workdays = array_map('intval', $eff['workdays']);
        $rows = $ctx['attendance'][$employee->id] ?? [];
        $leaveDates = $ctx['leave'][$employee->id] ?? [];

        $hire = $employee->hire_date?->toDateString();
        $separation = $employee->separation_date?->toDateString();
        $isManagement = $employee->isManagement();

        $days = [];
        $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'on_leave' => 0, 'half_day' => 0];

        $cursor = Carbon::createFromFormat('Y-m-d', $ctx['start'], $tz)->startOfDay();

        for ($day = 1; $day <= $ctx['days_in_month']; $day++, $cursor->addDay()) {
            $date = $cursor->toDateString();
            $row = $rows[$date] ?? null;
            $isWorkday = in_array($cursor->isoWeekday(), $workdays, true);
            $isHoliday = isset($ctx['holidays'][$date]);
            $onLeave = isset($leaveDates[$date]) && $isWorkday && ! $isHoliday;
            $employed = ($hire === null || $date >= $hire) && ($separation === null || $date <= $separation);
            // Today is still open — the employee can yet clock in — so it is never an absence.
            $judged = $date < $ctx['today'];

            $status = match (true) {
                ! $employed => 'not_employed',
                $row && $row->status_override => $row->status,
                $row && $row->time_in => $row->status,
                $onLeave => 'on_leave',
                $isHoliday && $isWorkday => 'holiday',
                $isManagement => $isWorkday ? 'present' : 'rest_day',
                $row && $row->status => $row->status,
                $isWorkday && $judged => 'absent',
                $isWorkday => 'upcoming',
                default => 'rest_day',
            };

            if (isset($counts[$status])) {
                $counts[$status]++;
            }

            $in = $row?->time_in?->copy()->setTimezone($tz);
            $out = $row?->time_out?->copy()->setTimezone($tz);

            $days[] = [
                'day' => $day,
                'date' => $date,
                'weekday' => $cursor->format('D'),
                'status' => $status,
                'leave_type' => $leaveDates[$date] ?? null,
                'holiday' => $ctx['holidays'][$date] ?? null,
                'time_in' => $in?->format('g:i A'),
                'time_out' => $out?->format('g:i A'),
                'in_minutes' => $in ? $in->hour * 60 + $in->minute : null,
                'out_minutes' => $out ? $out->hour * 60 + $out->minute : null,
                'hours' => (float) ($row?->hours_worked ?? 0),
                'note' => $row?->note,
            ];
        }

        // A half day was worked, so it counts as attendance; the unpaid half is a payroll matter
        // priced by AttendanceService::unpaidDaysDetailFor(), not something a rate should restate.
        $attended = $counts['present'] + $counts['late'] + $counts['half_day'];
        // Expected = the days this employee could have shown up for. Rest days, holidays, days
        // outside employment and days still to come are not owed, and leave is excused rather than
        // missed — so it leaves the denominator instead of counting against the rate.
        $expected = $attended + $counts['absent'];

        return [
            'days' => $days,
            'present' => $counts['present'] + $counts['half_day'],
            'late' => $counts['late'],
            'absent' => $counts['absent'],
            'on_leave' => $counts['on_leave'],
            'attended' => $attended,
            'expected' => $expected,
            'rate' => $expected > 0 ? (int) round($attended / $expected * 100) : null,
        ];
    }

    /** Mean clock time as `g:i A`, or null when there is nothing to average. */
    private function averageClock(array $minutes): ?string
    {
        $values = array_values(array_filter($minutes, fn ($m) => $m !== null));

        if ($values === []) {
            return null;
        }

        $mean = (int) round(array_sum($values) / count($values));

        return Carbon::createFromTime(intdiv($mean, 60), $mean % 60)->format('g:i A');
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'present' => 'Present',
            'late' => 'Late',
            'absent' => 'Absent',
            'on_leave' => 'On leave',
            'half_day' => 'Half day',
            'rest_day' => 'Rest day',
            'holiday' => 'Holiday',
            'not_employed' => 'Not employed',
            'upcoming' => 'Upcoming',
            default => ucwords(str_replace('_', ' ', $status)),
        };
    }
}
