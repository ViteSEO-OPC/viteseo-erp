<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\WorkSuspension;
use Illuminate\Support\Carbon;

/**
 * One employee's own attendance for a month, inside a year — the self-service counterpart to the
 * Reports hub's Attendance Summary.
 *
 * WHY IT DOES NOT REUSE AttendanceReportService. That service answers "what did EVERY person's
 * month look like" and deliberately carries only what a per-employee ROW needs: status, punches,
 * hours. The employee reading their own month needs the opposite shape — one person, every fact
 * about each of their days (how many minutes late, how much undertime, which overtime was
 * approved, which day HR suspended work) plus the twelve-month context that makes one month
 * readable. Widening the HR report's grid with per-minute detail would pay that cost once per
 * employee per month on a screen that shows none of it.
 *
 * IT DOES NOT RECLASSIFY DAYS. The status ladder is AttendanceService::allForDate()'s, in the same
 * order, so a day here reads exactly as it did on the roster HR looked at — with the three cases a
 * month view has and a single-day roster does not (outside employment, not yet happened, and leave
 * landing on a rest day or holiday, which stays a rest day).
 *
 * IT DOES NOT PRICE ANYTHING. Every figure is an attendance fact — minutes late, minutes short,
 * minutes of approved overtime — never pesos. What a policy charges for those minutes is the
 * payslip's answer, and two screens computing money from the same day is how they start
 * disagreeing.
 */
class MyAttendanceReportService
{
    /** Statuses a day can read as. Mirrors AttendanceService's ladder plus the month-only cases. */
    public const DAY_STATUSES = [
        'present', 'late', 'absent', 'on_leave', 'half_day', 'rest_day', 'holiday',
        'suspended_paid', 'suspended_unpaid', 'not_employed', 'upcoming',
    ];

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly ScheduleService $schedule,
        private readonly HolidayService $holidays,
        private readonly WorkSuspensionService $suspensions,
        private readonly HalfDayWindowService $halfDays,
    ) {}

    /**
     * The whole payload one screen needs: the filter options, the selected month day by day, its
     * totals, and the same totals for all twelve months of the year.
     *
     * @param  array<string, mixed>  $filters  `month` => 'YYYY-MM'
     * @return array<string, mixed>
     */
    public function build(Employee $employee, array $filters = []): array
    {
        $tz = config('app.timezone_local');
        $today = now($tz)->startOfDay();

        $month = $this->resolveMonth($filters['month'] ?? null, $today);
        $year = (int) substr($month, 0, 4);

        // ONE pass over the whole year, then read twelve months off it. The alternative — a month
        // at a time, twelve times — would ask every one of the lookups below for a range it has
        // already been asked for, and each of them memoizes BY RANGE (see the payroll perf
        // ratchet): one wide call is a single set of queries, twelve narrow ones are twelve.
        $start = Carbon::createFromFormat('Y-m-d', "{$year}-01-01", $tz)->startOfDay();
        $end = $start->copy()->endOfYear();

        $days = $this->gridFor($employee, $start, $end, $today, $tz);

        $monthDays = array_values(array_filter($days, fn ($d) => str_starts_with($d['date'], $month)));
        $monthLabel = Carbon::createFromFormat('Y-m-d', $month . '-01', $tz)->format('F Y');

        // Every month of the year, so the picker's options and the breakdown table are one list.
        $months = [];
        foreach (range(1, 12) as $m) {
            $cursor = Carbon::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $year, $m), $tz);
            $value = $cursor->format('Y-m');
            $slice = array_values(array_filter($days, fn ($d) => str_starts_with($d['date'], $value)));

            $months[] = [
                'value' => $value,
                'label' => $cursor->format('F Y'),
                'short_label' => $cursor->format('M'),
                // A month the employee had not been hired for (or that is still ahead) has nothing
                // to say; the screen greys it rather than showing a row of confident zeroes.
                'has_data' => $this->hasData($slice),
                'totals' => $this->totalsFor($slice),
            ];
        }

        return [
            'employee' => [
                'name' => $employee->user?->name ?? 'Unknown',
                'department' => $employee->department?->name,
                'hire_date' => $employee->hire_date?->toDateString(),
                // Management is exempt from the clock, and every zero on this page follows from
                // that fact rather than from the employee never being late. Say so.
                'is_management' => $employee->isManagement(),
            ],
            'schedule' => $this->scheduleSummary($employee),
            'years' => $this->yearOptions($employee, $year, $today),
            'selected_year' => $year,
            'months' => $months,
            'selected_month' => ['value' => $month, 'label' => $monthLabel],
            'month_in_progress' => $month === $today->format('Y-m'),
            'today' => $today->toDateString(),
            'days' => $monthDays,
            'totals' => $this->totalsFor($monthDays),
            'year_totals' => $this->totalsFor($days),
            'generated_at' => now($tz)->format('M j, Y g:i A'),
        ];
    }

    // ------------------------------------------------------------------ filters

    /** A `YYYY-MM` filter, or the current month when it is missing or malformed. */
    private function resolveMonth(mixed $month, Carbon $today): string
    {
        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1
            ? $month
            : $today->format('Y-m');
    }

    /**
     * The years the employee could have attendance in: hire year → this year, newest first, plus
     * the selected one when a link points outside that window.
     *
     * @return array<int, int>
     */
    private function yearOptions(Employee $employee, int $selected, Carbon $today): array
    {
        $first = (int) ($employee->hire_date?->format('Y') ?? $today->year);
        $years = range($today->year, min($first, $today->year));

        if (! in_array($selected, $years, true)) {
            $years[] = $selected;
        }

        rsort($years);

        return array_values($years);
    }

    /** @return array<string, mixed> */
    private function scheduleSummary(Employee $employee): array
    {
        $eff = $this->schedule->effectiveFor($employee);

        return [
            'name' => $eff['schedule_name'],
            'start_time' => $eff['start_time'],
            'end_time' => $eff['end_time'],
            'grace_minutes' => (int) $eff['grace_minutes'],
            'workdays' => $eff['workdays'],
        ];
    }

    // ----------------------------------------------------------------- day grid

    /**
     * Every day of the range, with the status it reads as and every minute-level fact about it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function gridFor(Employee $employee, Carbon $start, Carbon $end, Carbon $today, string $tz): array
    {
        $from = $start->toDateString();
        $to = $end->toDateString();

        $rows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from, $to])
            ->get()
            ->keyBy(fn (Attendance $r) => $r->work_date->toDateString());

        $leaveDates = $this->leaveDatesFor($employee, $from, $to);
        $holidays = $this->holidaysFor($from, $to);
        $suspensions = $this->suspensionsFor($from, $to);
        $earlyOuts = $this->earlyOutsFor($employee, $from, $to);

        // Every one of these memoizes by range inside AttendanceService, and they all share the
        // one injected instance — so this is one set of queries for the year, not one per call.
        $late = $this->attendance->lateMinutesFor($employee, $from, $to);
        $undertime = $this->attendance->undertimeMinutesFor($employee, $from, $to);
        $overtime = $this->attendance->approvedOvertimeMinutesFor($employee, $from, $to);
        $night = $this->attendance->nightMinutesFor($employee, $from, $to);
        $restDay = $this->attendance->restDayWorkedMinutesFor(
            $employee,
            $from,
            $to,
            $this->holidays->typedHolidaysBetween($start, $end),
        );

        $offHalves = $this->halfDays->offHalvesBetween($from, $to);

        // Workdays come off the schedule ONCE, without a date: which weekdays are worked cannot
        // vary by day, and asking effectiveFor() per date would add a grace-override lookup for
        // every day of the year to answer a question that never uses grace.
        $eff = $this->schedule->effectiveFor($employee);
        $workdays = array_map('intval', $eff['workdays']);

        $hire = $employee->hire_date?->toDateString();
        $separation = $employee->separation_date?->toDateString();
        $isManagement = $employee->isManagement();

        $days = [];
        $todayStr = $today->toDateString();

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $row = $rows->get($date);
            $isWorkday = in_array($cursor->isoWeekday(), $workdays, true);
            $holiday = $holidays[$date] ?? null;
            $isNonWorkingHoliday = $holiday !== null && in_array($holiday['type'], HolidayService::NON_WORKING_TYPES, true);
            $suspension = $suspensions[$date] ?? null;
            // Leave never lands on a day nobody was expected in — a rest day or a non-working
            // holiday stays what it is, because leave is not consumed by a day already off.
            $leave = ($isWorkday && ! $isNonWorkingHoliday) ? ($leaveDates[$date] ?? null) : null;
            $employed = ($hire === null || $date >= $hire) && ($separation === null || $date <= $separation);
            // Today is still open — the employee can yet clock in — so it is never an absence.
            $judged = $date < $todayStr;

            $status = match (true) {
                ! $employed => 'not_employed',
                $row && $row->status_override => $row->status,
                $row && $row->time_in => $row->status,
                $leave !== null => 'on_leave',
                $isNonWorkingHoliday && $isWorkday => 'holiday',
                // Ranked BELOW the holiday branch, exactly as allForDate ranks it: a holiday
                // already pays, so a suspension declared over one changes nothing.
                $suspension !== null && $isWorkday => $suspension['is_paid'] ? 'suspended_paid' : 'suspended_unpaid',
                $isManagement => $isWorkday ? 'present' : 'rest_day',
                $row && $row->status => $row->status,
                $isWorkday && $judged => 'absent',
                $isWorkday => 'upcoming',
                default => 'rest_day',
            };

            $in = $row?->time_in?->copy()->setTimezone($tz);
            $out = $row?->time_out?->copy()->setTimezone($tz);
            $ot = $overtime[$date] ?? null;

            $days[] = [
                'day' => $cursor->day,
                'date' => $date,
                'weekday' => $cursor->format('D'),
                'status' => $status,
                'is_workday' => $isWorkday,
                'time_in' => $in?->format('g:i A'),
                'time_out' => $out?->format('g:i A'),
                'hours' => round((float) ($row?->hours_worked ?? 0), 2),
                'late_minutes' => (int) ($late[$date] ?? 0),
                'undertime_minutes' => (int) ($undertime[$date] ?? 0),
                'overtime_minutes' => (int) ($ot['minutes'] ?? 0),
                // Night-differential minutes across the whole shift, not only its overtime tail —
                // the differential is earned by the hour, whoever authorised it.
                'night_minutes' => (int) ($night[$date] ?? 0),
                'rest_day_minutes' => (int) ($restDay[$date] ?? 0),
                'leave_type' => $leave['type'] ?? null,
                'leave_is_paid' => $leave['is_paid'] ?? null,
                // 'AM' | 'PM' | 'BOTH' — the half approved OFF, so a 1:00 PM start reads as the
                // half day it is rather than as an unexplained late arrival.
                'half_day_off' => $offHalves["{$employee->id}|{$date}"] ?? null,
                'early_out_time' => $earlyOuts[$date] ?? null,
                'holiday' => $holiday,
                'suspension' => $suspension,
                'note' => $row?->note,
            ];
        }

        return $days;
    }

    // ------------------------------------------------------------------- totals

    /**
     * The counts and minute totals for a set of days.
     *
     * @param  array<int, array<string, mixed>>  $days
     * @return array<string, mixed>
     */
    private function totalsFor(array $days): array
    {
        $counts = array_fill_keys(self::DAY_STATUSES, 0);
        $minutes = ['late' => 0, 'undertime' => 0, 'overtime' => 0, 'night' => 0, 'rest_day' => 0];
        $hours = 0.0;
        $daysLogged = 0;
        $leaveTypes = [];

        foreach ($days as $day) {
            $counts[$day['status']] = ($counts[$day['status']] ?? 0) + 1;
            $minutes['late'] += $day['late_minutes'];
            $minutes['undertime'] += $day['undertime_minutes'];
            $minutes['overtime'] += $day['overtime_minutes'];
            $minutes['night'] += $day['night_minutes'];
            $minutes['rest_day'] += $day['rest_day_minutes'];
            $hours += $day['hours'];

            if ($day['time_in'] !== null) {
                $daysLogged++;
            }
            if ($day['status'] === 'on_leave' && $day['leave_type'] !== null) {
                $leaveTypes[$day['leave_type']] = ($leaveTypes[$day['leave_type']] ?? 0) + 1;
            }
        }

        // A half day was worked, so it counts as attendance. Expected = the days this employee
        // could have shown up for: rest days, holidays, suspensions, days outside employment and
        // days still to come are not owed, and leave is excused rather than missed — so it leaves
        // the denominator instead of counting against the rate.
        $attended = $counts['present'] + $counts['late'] + $counts['half_day'];
        $expected = $attended + $counts['absent'];

        ksort($leaveTypes);

        return [
            'present' => $counts['present'] + $counts['half_day'],
            'late' => $counts['late'],
            'absent' => $counts['absent'],
            'on_leave' => $counts['on_leave'],
            'half_day' => $counts['half_day'],
            'holiday' => $counts['holiday'],
            'rest_day' => $counts['rest_day'],
            'suspended' => $counts['suspended_paid'] + $counts['suspended_unpaid'],
            'suspended_paid' => $counts['suspended_paid'],
            'suspended_unpaid' => $counts['suspended_unpaid'],
            'upcoming' => $counts['upcoming'],
            'days_logged' => $daysLogged,
            'expected_days' => $expected,
            'hours_worked' => round($hours, 2),
            'late_minutes' => $minutes['late'],
            'undertime_minutes' => $minutes['undertime'],
            'overtime_minutes' => $minutes['overtime'],
            'night_minutes' => $minutes['night'],
            'rest_day_minutes' => $minutes['rest_day'],
            'rate' => $expected > 0 ? (int) round($attended / $expected * 100) : null,
            'leave_by_type' => array_map(
                fn ($name, $count) => ['name' => $name, 'days' => $count],
                array_keys($leaveTypes),
                array_values($leaveTypes),
            ),
        ];
    }

    /**
     * True when anything at all happened in the slice — otherwise the month is empty, not zero.
     *
     * Rest days do NOT count. A month still ahead is nothing but weekends and upcoming workdays,
     * and letting its Saturdays vouch for it would put a confident row of zeroes against every
     * month of the rest of the year.
     */
    private function hasData(array $days): bool
    {
        $empty = ['not_employed', 'upcoming', 'rest_day'];

        foreach ($days as $day) {
            if (! in_array($day['status'], $empty, true)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ lookups

    /**
     * Dates covered by an APPROVED leave request, as date => the type's name and whether it pays.
     *
     * Requests are stored as a start/end pair, so they are expanded here; whether a given date
     * actually reads as leave is decided in gridFor().
     *
     * @return array<string, array{type:string, is_paid:bool}>
     */
    private function leaveDatesFor(Employee $employee, string $start, string $end): array
    {
        $out = [];

        $requests = LeaveRequest::with('leaveType')
            ->where('employee_id', $employee->id)
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
                $out[$cursor->toDateString()] = [
                    'type' => $request->leaveType?->name ?? 'Leave',
                    'is_paid' => (bool) ($request->leaveType?->is_paid ?? true),
                ];
            }
        }

        return $out;
    }

    /**
     * Home-country holidays in the range, as date => name and type. The name is why this does not
     * go through HolidayService::typedHolidaysBetween() — that returns types only, and a day
     * marked "Holiday" without saying which one is a fact the employee cannot check.
     *
     * @return array<string, array{name:string, type:string|null}>
     */
    private function holidaysFor(string $start, string $end): array
    {
        return Holiday::whereBetween('date', [$start, $end])
            ->whereHas('set', fn ($q) => $q->where('country_code', HolidayService::HOME_COUNTRY))
            ->get(['date', 'name', 'type'])
            ->mapWithKeys(fn (Holiday $h) => [
                $h->date->toDateString() => ['name' => $h->name, 'type' => $h->type],
            ])
            ->all();
    }

    /**
     * HR-declared non-working days, as date => name and whether the day is still paid.
     *
     * @return array<string, array{name:string, is_paid:bool}>
     */
    private function suspensionsFor(string $start, string $end): array
    {
        return WorkSuspension::whereBetween('date', [$start, $end])
            ->get(['date', 'name', 'is_paid'])
            ->mapWithKeys(fn (WorkSuspension $s) => [
                $s->date->toDateString() => ['name' => $s->name, 'is_paid' => (bool) $s->is_paid],
            ])
            ->all();
    }

    /**
     * Approved early-outs, as date => the time they were allowed to leave.
     *
     * @return array<string, string>
     */
    private function earlyOutsFor(Employee $employee, string $start, string $end): array
    {
        return EarlyOutRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereBetween('work_date', [$start, $end])
            ->get(['work_date', 'early_out_time'])
            ->mapWithKeys(fn (EarlyOutRequest $e) => [
                $e->work_date->toDateString() => substr((string) $e->early_out_time, 0, 5),
            ])
            ->all();
    }
}
