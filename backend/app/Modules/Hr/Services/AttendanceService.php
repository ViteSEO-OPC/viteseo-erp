<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    /** Fallback shift start if an employee has no schedule at all. */
    private const FALLBACK_START = '09:00';

    public function __construct(
        private readonly ScheduleService $schedule,
        private readonly ViolationService $violations,
        private readonly AttendancePolicyService $policy,
        private readonly AuditService $audit,
        private readonly DeductionService $deduction,
        private readonly HolidayService $holidays,
    ) {}

    /**
     * Non-working holidays in an inclusive range, as date 'Y-m-d' => type. Fetched ONCE per
     * caller (never per day) — the day loops below run per employee per date.
     *
     * `special_working` is filtered out on purpose: it is an ordinary working day and must keep
     * behaving like one. Unclassified holidays (type NULL — HR hasn't set them yet) are also
     * excluded: we never guess whether an unclassified date is a non-working day.
     *
     * @return array<string, string>
     */
    private function nonWorkingHolidays(string $start, string $end): array
    {
        $tz = config('app.timezone_local');

        return array_filter(
            $this->holidays->typedHolidaysBetween(Carbon::parse($start, $tz), Carbon::parse($end, $tz)),
            fn ($type) => in_array($type, HolidayService::NON_WORKING_TYPES, true),
        );
    }

    private function localNow(): Carbon
    {
        return now(config('app.timezone_local'));
    }

    /** Today's Manila calendar date (the work_date key). */
    private function today(): string
    {
        return $this->localNow()->toDateString();
    }

    private function todayRow(Employee $employee): ?Attendance
    {
        return Attendance::where('employee_id', $employee->id)
            ->where('work_date', $this->today())
            ->first();
    }

    /**
     * Longest a shift may stay open before we stop treating it as "in progress". Past this
     * it is a forgotten clock-out, not someone still at work — the violation engine flags it
     * and payroll credits the scheduled end. Generous enough for a 12h shift plus overtime.
     */
    private const MAX_OPEN_SHIFT_HOURS = 18;

    /**
     * The employee's shift that is currently IN PROGRESS — clocked in, not yet out — even
     * when it began on an earlier Manila date.
     *
     * WHY (Slice 3): a work_date is the Manila date the shift STARTED, so a graveyard shift
     * clocked in at 22:00 on the 1st is stored on the 1st. Every clock action used to look up
     * "today's" row, so at 06:00 on the 2nd the employee was told "You have not clocked in
     * today" and simply could not clock out. Night differential and past-midnight overtime
     * were therefore unreachable through the UI — only an HR correction could produce such a
     * row. This resolves the shift instead of the calendar day.
     */
    private function openRow(Employee $employee): ?Attendance
    {
        $row = Attendance::where('employee_id', $employee->id)
            ->whereNotNull('time_in')
            ->whereNull('time_out')
            // Two Manila days back is plenty to catch an overnight shift, and keeps this off
            // a full table scan.
            ->where('work_date', '>=', $this->localNow()->copy()->subDays(2)->toDateString())
            ->orderByDesc('work_date')
            ->first();

        if (! $row || ! $row->time_in) {
            return null;
        }

        // Stale punch → not an open shift, so the employee can start a fresh day tomorrow
        // instead of being permanently blocked by one they forgot to close.
        return $row->time_in->diffInHours(now()) >= self::MAX_OPEN_SHIFT_HOURS ? null : $row;
    }

    /**
     * The row the clock acts on: the shift in progress if there is one, else today's row.
     * The fallback preserves every existing "not clocked in" / "already clocked out" message.
     */
    private function activeRow(Employee $employee): ?Attendance
    {
        return $this->openRow($employee) ?? $this->todayRow($employee);
    }

    /** Management staff are exempt from attendance — they never clock in/out. */
    private function assertNotManagement(Employee $employee): void
    {
        if ($employee->isManagement()) {
            throw ValidationException::withMessages([
                'attendance' => ['Management employees are exempt from attendance.'],
            ]);
        }
    }

    public function clockIn(Employee $employee): Attendance
    {
        $this->assertNotManagement($employee);

        // An overnight shift from a previous date is still running — starting a second one
        // would leave the first permanently open and silently unpaid.
        $open = $this->openRow($employee);
        if ($open && $open->work_date->toDateString() !== $this->today()) {
            throw ValidationException::withMessages([
                'time_in' => [
                    'You still have an open shift from ' . $open->work_date->format('M j')
                        . ' — clock out first, or ask HR to correct it.',
                ],
            ]);
        }

        $att = Attendance::firstOrNew([
            'employee_id' => $employee->id,
            'work_date' => $this->today(),
        ]);

        if ($att->time_in) {
            throw ValidationException::withMessages([
                'time_in' => ['You have already clocked in today.'],
            ]);
        }

        $local = $this->localNow();
        // Lateness is measured against the employee's EFFECTIVE schedule (start +
        // grace), resolved from override → assigned → default — not a fixed cutoff.
        $eff = $this->schedule->effectiveFor($employee, $local);
        $cutoff = $this->scheduleStart($local, $eff)->addMinutes($eff['grace_minutes'] ?? 0);

        $att->time_in = now(); // stored UTC
        $att->status = $local->gt($cutoff) ? 'late' : 'present';
        $att->save();

        return $att;
    }

    /**
     * Toggle a lunch or break. First call starts it; second call ends it and
     * adds the elapsed minutes to the counter.
     */
    public function toggleBreak(Employee $employee, string $type): Attendance
    {
        $this->assertNotManagement($employee);

        $att = $this->activeRow($employee);

        if (! $att || ! $att->time_in) {
            throw ValidationException::withMessages([
                'type' => ['Clock in before starting a break.'],
            ]);
        }
        if ($att->time_out) {
            throw ValidationException::withMessages([
                'type' => ['You have already clocked out.'],
            ]);
        }

        $startedField = "{$type}_started_at";
        $minutesField = "{$type}_minutes";

        if ($att->{$startedField} === null) {
            $att->{$startedField} = now();
        } else {
            $att->{$minutesField} += $att->{$startedField}->diffInMinutes(now());
            $att->{$startedField} = null;
        }

        $att->save();

        return $att;
    }

    /**
     * @param  array<int, array{text:string, done:bool}>  $tasks  Tasks logged at time-out.
     */
    public function clockOut(Employee $employee, array $tasks = []): Attendance
    {
        $this->assertNotManagement($employee);

        $att = $this->activeRow($employee);

        if (! $att || ! $att->time_in) {
            throw ValidationException::withMessages([
                'time_out' => ['You have not clocked in today.'],
            ]);
        }
        if ($att->time_out) {
            throw ValidationException::withMessages([
                'time_out' => ['You have already clocked out today.'],
            ]);
        }

        // Keep only non-empty task rows; store null when there are none.
        $cleanTasks = array_values(array_filter(
            array_map(fn ($t) => [
                'text' => trim((string) ($t['text'] ?? '')),
                'done' => (bool) ($t['done'] ?? false),
            ], $tasks),
            fn ($t) => $t['text'] !== '',
        ));
        $att->tasks = $cleanTasks ?: null;

        // Auto-close any still-open lunch/break so the math is complete.
        foreach (['lunch', 'break'] as $type) {
            $startedField = "{$type}_started_at";
            if ($att->{$startedField} !== null) {
                $att->{"{$type}_minutes"} += $att->{$startedField}->diffInMinutes(now());
                $att->{$startedField} = null;
            }
        }

        $att->time_out = now();

        $grossMinutes = $att->time_in->diffInMinutes($att->time_out);
        $netMinutes = $grossMinutes - $att->lunch_minutes - $att->break_minutes;
        $att->hours_worked = round(max(0, $netMinutes) / 60, 2);
        $att->save();

        return $att;
    }

    public function todayFor(Employee $employee): ?Attendance
    {
        return $this->activeRow($employee);
    }

    /**
     * HR manually sets/corrects an employee's attendance for a date (e.g. they
     * forgot to clock in, or worked despite booking leave → set Present). Times
     * come as "HH:MM" in Manila; stored UTC.
     *
     * @param  array<string, mixed>  $data
     */
    public function adjust(Employee $employee, string $workDate, array $data): Attendance
    {
        $tz = config('app.timezone_local');
        $att = Attendance::firstOrNew([
            'employee_id' => $employee->id,
            'work_date' => $workDate,
        ]);

        // F-7: capture the row BEFORE it is overwritten. An HR edit to time_in is an edit
        // to someone's attendance record — and once minutes become money, to their pay.
        // adjust() overwrites in place, so without this the old values are destroyed.
        $wasExisting = $att->exists;
        $before = $wasExisting ? [
            'time_in' => $att->time_in?->toIso8601String(),
            'time_out' => $att->time_out?->toIso8601String(),
            'status' => $att->status,
            'hours_worked' => (float) $att->hours_worked,
            'note' => $att->note,
        ] : null;

        // Status is DERIVED from the times, never sent by the client: no time-in means
        // absent; otherwise present vs late is decided against the employee's effective
        // schedule (start + grace) for that day — the same rule as a live clock-in.
        if (empty($data['time_in'])) {
            $att->time_in = null;
            $att->time_out = null;
            $att->hours_worked = 0;
            $att->status = 'absent';
        } else {
            // Parse the "HH:MM" as Manila wall-clock, then convert to UTC before
            // storing — Eloquent formats the Carbon's own wall-clock into the column
            // WITHOUT converting tz, so a Manila-tz Carbon would be saved 8h off.
            $day = Carbon::parse($workDate, $tz)->startOfDay();
            $timeIn = Carbon::parse("{$workDate} {$data['time_in']}", $tz);
            $att->time_in = $timeIn->copy()->utc();
            $att->time_out = ! empty($data['time_out'])
                ? Carbon::parse("{$workDate} {$data['time_out']}", $tz)->utc() : null;

            $eff = $this->schedule->effectiveFor($employee, $day);
            $cutoff = $this->scheduleStart($day, $eff)->addMinutes($eff['grace_minutes'] ?? 0);
            $att->status = $timeIn->gt($cutoff) ? 'late' : 'present';

            if ($att->time_in && $att->time_out) {
                $gross = ($att->time_out->getTimestamp() - $att->time_in->getTimestamp()) / 60;
                $net = $gross - $att->lunch_minutes - $att->break_minutes;
                $att->hours_worked = round(max(0, $net) / 60, 2);
            } else {
                $att->hours_worked = 0;
            }
        }

        $att->note = $data['note'] ?? null;
        // A human just attested to this row, so it is no longer system-generated —
        // and the nightly management job will now leave it alone.
        $att->source = Attendance::SOURCE_MANUAL;
        $att->save();

        // F-7: log the correction with before/after. Every other service in the module
        // audit-logs its writes; this one silently rewrote attendance until now.
        $employee->loadMissing('user');
        $name = $employee->user?->name ?? $employee->name;
        $this->audit->log(
            $wasExisting ? 'updated' : 'created',
            'Attendance',
            ($wasExisting ? 'Adjusted' : 'Created') . " attendance for {$name} on {$workDate}",
            $att,
            $before,
            [
                'time_in' => $att->time_in?->toIso8601String(),
                'time_out' => $att->time_out?->toIso8601String(),
                'status' => $att->status,
                'hours_worked' => (float) $att->hours_worked,
                'note' => $att->note,
            ],
        );

        // A correction can change someone's flags — keep them in sync.
        $this->violations->recompute();

        return $att;
    }

    /** The employee's rows for the current Manila week (Mon–Sun), newest first. */
    public function currentWeek(Employee $employee): Collection
    {
        $start = $this->localNow()->startOfWeek()->toDateString();
        $end = $this->localNow()->endOfWeek()->toDateString();

        return Attendance::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start, $end])
            ->orderByDesc('work_date')
            ->get();
    }

    /**
     * The 8 summary tiles for a date (default today), computed against each
     * employee's schedule + approved leave.
     *
     * @return array<string, mixed>
     */
    public function summary(?string $date = null): array
    {
        $tz = config('app.timezone_local');
        $date ??= $this->today();
        $dateCarbon = Carbon::parse($date, $tz)->startOfDay();
        $isPast = $dateCarbon->lt($this->localNow()->startOfDay());

        $employees = Employee::with('workSchedule')->get();
        $rows = Attendance::where('work_date', $date)->get()->keyBy('employee_id');
        $onLeaveIds = $this->onLeaveIds($date);
        // A non-working holiday is not an absence (Slice 1) — nobody is expected in.
        $isHoliday = $this->nonWorkingHolidays($date, $date) !== [];

        $s = [
            'checked_in' => 0, 'not_checked_in' => 0, 'on_leave' => 0,
            'early_arrivals' => 0, 'late_arrivals' => 0, 'on_time' => 0,
            'total_employees' => $employees->count(), 'absent' => 0,
        ];

        foreach ($employees as $emp) {
            $eff = $this->schedule->effectiveFor($emp, $dateCarbon);
            $row = $rows->get($emp->id);
            $isOnLeave = $onLeaveIds->contains($emp->id);

            if ($isOnLeave) {
                $s['on_leave']++;
            }

            // Management: exempt from attendance — auto-present (on time) on scheduled
            // workdays, never absent/not-checked-in. On leave/rest days, fall through.
            if ($emp->isManagement()) {
                if (! $isOnLeave && $eff['is_workday']) {
                    $s['checked_in']++;
                    $s['on_time']++;
                }

                continue;
            }

            if ($row && $row->time_in) {
                $s['checked_in']++;
                $cat = $this->arrivalCategory($row->time_in->copy()->setTimezone($tz), $eff, $dateCarbon)['category'];
                $s[$cat === 'early' ? 'early_arrivals' : ($cat === 'late' ? 'late_arrivals' : 'on_time')]++;
            } elseif (! $isOnLeave && $eff['is_workday'] && ! $isHoliday) {
                // No clock-in on a workday: it's a true absence only once the day has
                // passed (or HR explicitly marked it absent). During today the employee
                // can still clock in, so it's "not checked in" — mirrors allForDate().
                // A non-working holiday is neither: nobody was expected in.
                if ($isPast || ($row && $row->status === 'absent')) {
                    $s['absent']++;
                } else {
                    $s['not_checked_in']++;
                }
            }
        }

        return ['date' => $date] + $s;
    }

    /**
     * HR roster: every employee's status for a date (default today), enriched with
     * department, arrival timing, on-leave type, and their worst violation flag.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allForDate(?string $date = null): array
    {
        $tz = config('app.timezone_local');
        $date ??= $this->today();
        $dateCarbon = Carbon::parse($date, $tz)->startOfDay();
        $isPast = $dateCarbon->lt($this->localNow()->startOfDay());

        // Keep violation flags current for the roster.
        $this->violations->recompute();
        $flags = $this->violations->flagsByEmployee();

        $rows = Attendance::where('work_date', $date)->get()->keyBy('employee_id');
        $leaveMap = $this->onLeaveMap($date);
        // A non-working holiday is not an absence (Slice 1) — nobody is expected in.
        $isHoliday = $this->nonWorkingHolidays($date, $date) !== [];

        return Employee::with(['user', 'department', 'workSchedule'])
            ->get()
            ->map(function (Employee $emp) use ($rows, $leaveMap, $flags, $tz, $dateCarbon, $date, $isPast, $isHoliday) {
                $att = $rows->get($emp->id);
                $eff = $this->schedule->effectiveFor($emp, $dateCarbon);
                $leaveName = $leaveMap->get($emp->id);

                $timing = ($att && $att->time_in)
                    ? $this->arrivalCategory($att->time_in->copy()->setTimezone($tz), $eff, $dateCarbon)
                    : null;

                if ($att && $att->time_in) {
                    $status = $att->status; // present | late
                } elseif ($leaveName) {
                    $status = 'on_leave';
                } elseif ($isHoliday && ($eff['is_workday'] ?? false)) {
                    // Non-working holiday on what would have been a workday — not an absence.
                    $status = 'holiday';
                } elseif ($emp->isManagement()) {
                    // Management is exempt from attendance — auto-present on a scheduled
                    // workday, rest day otherwise. Never absent/not-checked-in.
                    $status = $eff['is_workday'] ? 'present' : 'rest_day';
                } elseif ($att && $att->status) {
                    $status = $att->status; // HR-set (e.g. absent)
                } elseif ($eff['is_workday'] && $isPast) {
                    $status = 'absent';
                } else {
                    $status = $eff['is_workday'] ? 'not_checked_in' : 'rest_day';
                }

                return [
                    'employee_id' => $emp->id,
                    'name' => $emp->user?->name,
                    'department' => $emp->department?->name,
                    'hire_date' => $emp->hire_date?->format('Y-m-d'),
                    'schedule' => [
                        'name' => $eff['schedule_name'],
                        'start_time' => $eff['start_time'],
                        'end_time' => $eff['end_time'],
                        'grace_minutes' => $eff['grace_minutes'],
                        'workdays' => $eff['workdays'],
                        'is_workday' => $eff['is_workday'],
                    ],
                    'work_date' => $date,
                    'time_in' => $att?->time_in,
                    'time_out' => $att?->time_out,
                    'first_in' => $att?->time_in,
                    'last_out' => $att?->time_out,
                    'hours_worked' => $att ? (float) $att->hours_worked : 0,
                    'status' => $status,
                    'leave_type' => $leaveName,
                    'timing' => $timing,
                    'flag' => $flags[$emp->id] ?? null,
                    'tasks' => $att?->tasks ?? [],
                    'note' => $att?->note,
                    // Lets HR see at a glance which rows the platform generated.
                    'source' => $att?->source,
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Scheduled workdays for an employee in an inclusive date range, per their
     * EFFECTIVE schedule (not a flat Mon–Fri). This is the payroll daily-rate
     * divisor, so proration matches the days the person is actually expected to work.
     */
    /**
     * Dates the employee actually worked in an inclusive range (a present/late
     * attendance record), as 'Y-m-d' strings — used to price holiday-premium pay.
     *
     * @return array<int, string>
     */
    /**
     * Minutes actually WORKED on a scheduled rest day (RD-1), per date — the basis for the rest-day
     * premium. A rest day is any date the employee's effective schedule says is not a workday; the
     * salary doesn't cover it, so working it is extra work.
     *
     * Dates that are also HOLIDAYS are excluded: holidayPremiumFor() already pays those, and stacking
     * both premiums on one day is the double-premium case left to a later refinement.
     *
     * Worked minutes = the clock window minus the unpaid meal. Needs both punches; management earns none.
     *
     * @param  array<string, string>  $holidayMap  date => holiday type (to exclude)
     * @return array<string, int> date 'Y-m-d' => worked minutes
     */
    public function restDayWorkedMinutesFor(Employee $employee, string $start, string $end, array $holidayMap = []): array
    {
        if ($employee->isManagement()) {
            return [];
        }

        $tz = config('app.timezone_local');
        $rows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start, $end])
            ->whereNotNull('time_in')
            ->whereNotNull('time_out')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $day = Carbon::parse($row->work_date, $tz);
            $date = $day->toDateString();
            // Skip ONLY the days holidayPremiumFor() actually pays for — regular and special
            // non-working. It used to skip every date in the map, which silently unpaid two
            // cases: a `special_working` day (an ordinary working day, earns no holiday
            // premium) and an unclassified one (type NULL, also earns nothing) — working
            // either on a rest day left the employee with no premium from either line.
            if (in_array($holidayMap[$date] ?? null, HolidayService::NON_WORKING_TYPES, true)) {
                continue; // the holiday premium covers this day
            }
            if ($this->schedule->effectiveFor($employee, $day)['is_workday'] ?? true) {
                continue; // an ordinary scheduled workday
            }

            $inMin = $this->clockToMinutes($row->time_in);
            $outMin = $this->clockToMinutes($row->time_out);
            if ($inMin === null || $outMin === null) {
                continue;
            }
            if ($outMin < $inMin) {
                $outMin += 24 * 60;
            }

            $worked = ($outMin - $inMin) - (int) $row->lunch_minutes;
            if ($worked > 0) {
                $out[$date] = $worked;
            }
        }

        return $out;
    }

    /** The night-differential window (Manila): 22:00 → 06:00. */
    public const NIGHT_START_MIN = 22 * 60;

    public const NIGHT_END_MIN = 6 * 60;

    /**
     * Minutes of a worked window that fall inside the night window (ND-1). Both arguments are
     * minute-of-day on the work date; `out` may exceed 1440 when the shift crosses midnight. In that
     * coordinate space the night bands are [00:00–06:00] of the work date and [22:00 → next 06:00].
     * Pure/static so the overlap arithmetic is unit-testable without a DB.
     */
    public static function nightOverlapMinutes(int $in, int $out): int
    {
        $overlap = static fn (int $a0, int $a1, int $b0, int $b1): int => max(0, min($a1, $b1) - max($a0, $b0));

        return $overlap($in, $out, 0, self::NIGHT_END_MIN)
            + $overlap($in, $out, self::NIGHT_START_MIN, 24 * 60 + self::NIGHT_END_MIN);
    }

    /**
     * Night-shift minutes per date (ND-1) — for EVERY worked day, not just overtime, since the PH
     * night differential applies to all night work. Needs both punches; management earns none.
     *
     * UNPAID BREAK: the differential is for hours WORKED, so the unpaid meal has to come out — but the
     * clock doesn't record when it was taken. We therefore subtract only the part that MUST have fallen
     * at night: whatever the daytime portion of the shift couldn't absorb. A graveyard 22:00–06:00 with
     * a 60-min lunch loses exactly 60 (no daytime to absorb it); an evening 14:00–23:30 loses none (its
     * 8 daytime hours could hold the whole break). Conservative by construction — it never under-pays.
     *
     * @return array<string, int> date 'Y-m-d' => night minutes
     */
    public function nightMinutesFor(Employee $employee, string $start, string $end): array
    {
        if ($employee->isManagement()) {
            return [];
        }

        $tz = config('app.timezone_local');
        $rows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start, $end])
            ->whereNotNull('time_in')
            ->whereNotNull('time_out')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $inMin = $this->clockToMinutes($row->time_in);
            $outMin = $this->clockToMinutes($row->time_out);
            if ($inMin === null || $outMin === null) {
                continue;
            }
            if ($outMin < $inMin) {
                $outMin += 24 * 60; // clocked out after midnight
            }

            $minutes = self::nightOverlapMinutes($inMin, $outMin);
            if ($minutes > 0) {
                // Carve out only the unpaid meal that the daytime part of the shift can't account for.
                $daytime = ($outMin - $inMin) - $minutes;
                $minutes = max(0, $minutes - max(0, (int) $row->lunch_minutes - $daytime));
            }
            if ($minutes > 0) {
                $out[Carbon::parse($row->work_date, $tz)->toDateString()] = $minutes;
            }
        }

        return $out;
    }

    /**
     * Auto-detected overtime (OT-5): minutes clocked out PAST the scheduled end, per date. Counted
     * only on scheduled workdays that have BOTH punches — a missing clock-out is credited to the
     * scheduled end (slice B) and can never become overtime. A day whose overrun is under
     * `thresholdMinutes` is ignored, so trivial overruns don't create pay. Management earns none.
     *
     * Rest-day work is deliberately NOT auto-detected: there is no shift to be "beyond", and pricing
     * a whole worked rest day belongs to the rest-day premium (Phase 3). File it as an OT request.
     *
     * Each entry also reports how much of that overrun fell in the NIGHT window, so the night
     * differential can be compounded onto the overtime rate for those minutes (ND-1 tightening).
     *
     * @return array<string, array{minutes:int, night:int}> date 'Y-m-d' => overtime minutes
     */
    public function autoOvertimeMinutesFor(Employee $employee, string $start, string $end, int $thresholdMinutes = 0): array
    {
        if ($employee->isManagement()) {
            return [];
        }

        $tz = config('app.timezone_local');
        $rows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start, $end])
            ->whereNotNull('time_in')
            ->whereNotNull('time_out')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $day = Carbon::parse($row->work_date, $tz);
            $eff = $this->schedule->effectiveFor($employee, $day);
            if (! ($eff['is_workday'] ?? false)) {
                continue; // rest day — see the note above
            }

            $endMin = $this->hmToMinutes($eff['end_time'] ?? null);
            $inMin = $this->clockToMinutes($row->time_in);
            $outMin = $this->clockToMinutes($row->time_out);
            if ($endMin === null || $inMin === null || $outMin === null) {
                continue;
            }
            if ($outMin < $inMin) {
                $outMin += 24 * 60; // clocked out after midnight
            }

            $minutes = $outMin - $endMin;
            if ($minutes > 0 && $minutes >= max(0, $thresholdMinutes)) {
                $out[$day->toDateString()] = [
                    'minutes' => $minutes,
                    'night' => self::nightOverlapMinutes($endMin, $outMin),
                ];
            }
        }

        return $out;
    }

    /**
     * How many overtime minutes the TIME CLOCK can support on each date (Slice 6) — the ceiling
     * an approved request is paid against. A date missing from the map supports none.
     *
     * Two shapes, because "beyond the shift" means different things:
     *   - a scheduled WORKDAY → minutes clocked past the schedule's end time;
     *   - a REST day / non-workday → there is no shift to be beyond, and the first 8 hours are
     *     the rest-day premium (see PayrollService::REST_DAY_PREMIUM_CAP_MIN), so only the
     *     excess past 8h can be overtime.
     *
     * Both punches are required: a missing clock-out can never become overtime (rule 5).
     *
     * @return array<string, int> date 'Y-m-d' => max payable overtime minutes
     */
    public function overtimeCapacityFor(Employee $employee, string $start, string $end): array
    {
        if ($employee->isManagement()) {
            return [];
        }

        $tz = config('app.timezone_local');
        $rows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start, $end])
            ->whereNotNull('time_in')
            ->whereNotNull('time_out')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $day = Carbon::parse($row->work_date, $tz);
            $eff = $this->schedule->effectiveFor($employee, $day);

            $inMin = $this->clockToMinutes($row->time_in);
            $outMin = $this->clockToMinutes($row->time_out);
            if ($inMin === null || $outMin === null) {
                continue;
            }
            if ($outMin < $inMin) {
                $outMin += 24 * 60; // clocked out after midnight
            }

            if ($eff['is_workday'] ?? false) {
                $endMin = $this->hmToMinutes($eff['end_time'] ?? null);
                if ($endMin === null) {
                    continue;
                }
                if ($outMin < $inMin) {
                    $endMin += 24 * 60;
                }
                $capacity = $outMin - $endMin;
            } else {
                // Rest day: worked time minus the unpaid meal, less the 8h premium block.
                $worked = ($outMin - $inMin) - (int) $row->lunch_minutes;
                $capacity = $worked - self::REST_DAY_PREMIUM_CAP_MIN;
            }

            if ($capacity > 0) {
                $out[$day->toDateString()] = $capacity;
            }
        }

        return $out;
    }

    /** A full 8-hour day in minutes — mirrors PayrollService::REST_DAY_PREMIUM_CAP_MIN. */
    private const REST_DAY_PREMIUM_CAP_MIN = 8 * 60;

    public function workedDatesBetween(Employee $employee, string $start, string $end): array
    {
        return Attendance::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start, $end])
            ->whereIn('status', ['present', 'late'])
            ->pluck('work_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();
    }

    public function scheduledWorkdaysFor(Employee $employee, string $start, string $end): int
    {
        $tz = config('app.timezone_local');
        $last = Carbon::parse($end, $tz)->startOfDay();
        $count = 0;

        for ($d = Carbon::parse($start, $tz)->startOfDay(); $d->lte($last); $d->addDay()) {
            if ($this->schedule->effectiveFor($employee, $d)['is_workday']) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Unpaid days for an employee in an inclusive date range — the PAYROLL view of
     * attendance, mirroring the roster classification in allForDate(). Over the
     * employee's scheduled workdays, a day is UNPAID when it's an explicit `absent`
     * row, OR a PAST workday with no clock-in and no approved leave (a missing record
     * is not free pay). Present/late days, PAID leave, rest days, and today/future
     * days do not count. UNPAID leave (LWOP) is deducted a full day (leave is full-day
     * only). An approved HALF-DAY request deducts 0.5 (two on one day → 1.0). Returns
     * a float so half-days survive.
     *
     * Tardiness adds an EXTRA penalty per the attendance policy: when late_penalty_enabled
     * is on, every N lates in the range deducts one more day. This is now an INDEPENDENT
     * switch, so it can stack on top of a per-time (per_hour/tiered) charge — that stacking
     * is deliberate and surfaced in the UI as a double-charge warning.
     */
    public function unpaidDaysFor(Employee $employee, string $start, string $end): float
    {
        return $this->unpaidDaysDetailFor($employee, $start, $end)->unpaidDays;
    }

    /**
     * Like unpaidDaysFor(), but also returns the minute-level late/undertime breakdown so
     * the payslip can explain its deduction (step 4b). The total is identical to
     * unpaidDaysFor(); the breakdown is all-zero until a per-hour/tiered mode is enabled.
     */
    public function unpaidDaysDetailFor(Employee $employee, string $start, string $end, ?AttendancePolicy $policyOverride = null): UnpaidDaysResult
    {
        // Management staff are on a FIXED salary — they never accrue unpaid days,
        // regardless of the caller. Guarding here (not only in PayrollService) keeps
        // any future caller correct.
        if ($employee->isManagement()) {
            return new UnpaidDaysResult(0.0);
        }

        $tz = config('app.timezone_local');
        $today = $this->localNow()->startOfDay();
        $last = Carbon::parse($end, $tz)->startOfDay();

        $rows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start, $end])
            ->get()
            ->keyBy(fn ($r) => $r->work_date->toDateString());

        $leaves = LeaveRequest::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get();

        // Approved half-day requests per date, keeping the AM/PM period (F-3 needs to know
        // which half was worked). AM + PM on one day → 2 halves = a full day.
        $halfDays = HalfDayRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereBetween('work_date', [$start, $end])
            ->get()
            ->groupBy(fn ($h) => $h->work_date->toDateString());

        // Approved early-out (undertime) requests per date — each deducts a fraction of
        // a day (undertime hours ÷ scheduled hours), applied only to days actually worked.
        $earlyOuts = EarlyOutRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereBetween('work_date', [$start, $end])
            ->get()
            ->groupBy(fn ($e) => $e->work_date->toDateString());

        // A transient policy (preview/dry-run) can be passed to price a period under
        // PROPOSED values without touching the saved singleton (step 6b).
        $policy = $policyOverride ?? $this->policy->get();
        // Fast path: with no per-minute mode on, DeductionService always returns zero, so
        // skip building any context — the loop then behaves exactly as it did pre-Slice-2.
        $deductionsActive = in_array($policy->late_deduction_mode, ['per_hour', 'tiered'], true)
            || $policy->undertime_deduction_mode === 'per_hour';

        // Non-working holidays in the cycle (Slice 1). One query for the whole range.
        $holidays = $this->nonWorkingHolidays($start, $end);

        // The employment window (Slice 4). The DIVISOR deliberately stays the full period's
        // scheduled workdays — under the `period_workdays` basis, shrinking it would raise the
        // daily rate and hand a part-period employee a full period's pay. Proration belongs on
        // the unpaid side, which is what the loop does.
        $employmentStart = $employee->hire_date?->toDateString();
        $employmentEnd = $employee->separation_date?->toDateString();

        $unpaid = 0.0;
        $lateCount = 0;
        $lateMinutes = 0;
        $undertimeMinutes = 0;
        $lateDays = 0.0;
        $undertimeDays = 0.0;
        $flags = [];
        // DOLE: holiday pay for a REGULAR holiday is forfeited when the employee was absent
        // without pay on the workday immediately before it. Null = no prior workday seen yet
        // in this range, in which case we don't know and pay the holiday (employee-favourable).
        $prevWorkdayUnpaid = null;

        for ($d = Carbon::parse($start, $tz)->startOfDay(); $d->lte($last); $d->addDay()) {
            $eff = $this->schedule->effectiveFor($employee, $d);
            if (! $eff['is_workday']) {
                continue; // rest day — never unpaid
            }

            $dateStr = $d->toDateString();

            // --- Outside the employment window (Slice 4) ----------------------------------
            // Days before the hire date or after the last working day are simply not owed.
            // This must run BEFORE the past/future test below: a mid-cycle leaver's remaining
            // days are still in the FUTURE, and the "no record yet, so don't charge it" rule
            // would otherwise have paid them for days after they left. Pre-hire days already
            // behaved this way by accident (a past workday with no record); now it is explicit
            // and also correct for a cycle that has not finished.
            if ($employmentStart !== null && $dateStr < $employmentStart) {
                $unpaid += 1.0;

                continue;
            }
            if ($employmentEnd !== null && $dateStr > $employmentEnd) {
                $unpaid += 1.0;

                continue;
            }

            // --- Non-working holiday (Slice 1) -------------------------------------------
            // The monthly salary already covers a regular / special non-working holiday, so
            // NOT working one is never an absence. Before this, every unworked holiday was
            // charged a full day's pay (verified: Independence Day cost one day).
            //
            // A holiday that WAS worked falls through to the normal paid-day path below, so
            // late/undertime still price normally and PayrollService::holidayPremiumFor()
            // pays the premium on top. Only the unworked case short-circuits here.
            $holidayType = $holidays[$dateStr] ?? null;
            if ($holidayType !== null) {
                $holidayRow = $rows->get($dateStr);
                if (! ($holidayRow && $holidayRow->time_in)) {
                    // Regular holidays are forfeited after an unpaid absence the workday
                    // before; special non-working days carry no such condition.
                    if ($holidayType === 'regular' && $prevWorkdayUnpaid === true) {
                        $unpaid += 1.0;
                    }

                    // A holiday is not a workday for this purpose — it can neither break nor
                    // extend the "absent the day before" chain, so leave the flag as it was.
                    continue;
                }
            }

            // Approved leave covering this day: paid leave → no deduction; unpaid leave
            // (LWOP) → the full day is deducted (leave is full-day only).
            $leave = $leaves->first(fn ($l) => $dateStr >= Carbon::parse($l->start_date)->toDateString()
                && $dateStr <= Carbon::parse($l->end_date)->toDateString());
            if ($leave) {
                $isUnpaidLeave = $leave->leaveType && ! $leave->leaveType->is_paid;
                if ($isUnpaidLeave) {
                    $unpaid += 1.0;
                }
                // LWOP the day before a regular holiday forfeits the holiday pay; paid leave
                // does not.
                $prevWorkdayUnpaid = $isUnpaidLeave;

                continue;
            }

            $row = $rows->get($dateStr);

            // Approved half-day request(s): 0.5 each. With two halves the whole day is off.
            // With one half off, the employee is expected to work the OTHER half — so a
            // per-minute mode still prices late/undertime against that half (F-3).
            if ($dayHalves = ($halfDays[$dateStr] ?? null)) {
                $halfUnpaid = 0.5 * $dayHalves->count();
                $unpaid += $halfUnpaid;

                if ($deductionsActive && $dayHalves->count() === 1 && $row && $row->time_in) {
                    $ded = $this->deduction->forDay(
                        $this->deductionCtx($row, $eff, $policy, (string) $dayHalves->first()->period, 1.0 - $halfUnpaid, isset($earlyOuts[$dateStr])),
                        $policy,
                    );
                    $unpaid += $ded->unpaidFraction;
                    $lateMinutes += $ded->lateMinutes;
                    $undertimeMinutes += $ded->undertimeMinutes;
                    $lateDays += $ded->lateFraction;
                    $undertimeDays += $ded->undertimeFraction;
                    $flags = array_merge($flags, $ded->flags);
                }

                // Half a day worked is not an absence, so it doesn't forfeit a following
                // regular holiday.
                $prevWorkdayUnpaid = false;

                continue; // the half-day itself is handled; skip absence classification
            }

            if ($row && $row->status === 'late') {
                $lateCount++;
            }
            if ($row && $row->time_in) {
                // Present/late day is paid. An approved early-out deducts its undertime
                // fraction (unchanged, ÷ scheduled span); clock-derived late/undertime is
                // priced by DeductionService (÷ 8) only when a mode is on. The two never
                // double-charge — DeductionService skips a day an early-out governs.
                $eoFrac = 0.0;
                foreach (($earlyOuts[$dateStr] ?? []) as $eo) {
                    $eoFrac += $this->earlyOutFraction((string) $eo->early_out_time, $eff);
                }
                $eoFrac = min(1.0, $eoFrac);
                $unpaid += $eoFrac;

                if ($deductionsActive) {
                    $ded = $this->deduction->forDay(
                        $this->deductionCtx($row, $eff, $policy, null, 1.0 - $eoFrac, isset($earlyOuts[$dateStr])),
                        $policy,
                    );
                    $unpaid += $ded->unpaidFraction;
                    $lateMinutes += $ded->lateMinutes;
                    $undertimeMinutes += $ded->undertimeMinutes;
                    $lateDays += $ded->lateFraction;
                    $undertimeDays += $ded->undertimeFraction;
                    $flags = array_merge($flags, $ded->flags);
                }

                $prevWorkdayUnpaid = false; // they showed up

                continue; // present or late → the day itself is paid
            }
            if ($row && $row->status === 'absent') {
                $unpaid += 1.0;
                $prevWorkdayUnpaid = true;

                continue;
            }
            // No usable record: unpaid only once the workday is in the past.
            if ($d->lt($today)) {
                $unpaid += 1.0;
                $prevWorkdayUnpaid = true;
            } else {
                $prevWorkdayUnpaid = false; // today/future — not an absence yet
            }
        }

        // Tardiness penalty: every N lates in the range costs one day's pay. Now an
        // INDEPENDENT switch, so it can stack on top of the per-time (`per_hour`) charge.
        if ($policy->late_penalty_enabled && $policy->lates_per_deduction > 0) {
            $unpaid += intdiv($lateCount, (int) $policy->lates_per_deduction);
        }

        return new UnpaidDaysResult(
            unpaidDays: $unpaid,
            lateMinutes: $lateMinutes,
            undertimeMinutes: $undertimeMinutes,
            lateDays: round($lateDays, 6),
            undertimeDays: round($undertimeDays, 6),
            flags: array_values(array_unique($flags)),
        );
    }

    /**
     * Build the DeductionService context for one worked day: schedule window + break
     * facts + the clock times as Manila minute-of-day.
     *
     * @param  array<string, mixed>  $eff
     * @return array<string, mixed>
     */
    private function deductionCtx(Attendance $row, array $eff, AttendancePolicy $policy, ?string $halfDayOff, float $capRemaining, bool $governedByEarlyOut): array
    {
        return [
            'start_min' => $this->hmToMinutes($eff['start_time'] ?? null),
            'end_min' => $this->hmToMinutes($eff['end_time'] ?? null),
            'grace_min' => (int) ($eff['grace_minutes'] ?? 0),
            'am_start_min' => $this->hmToMinutes($eff['am_start_time'] ?? null),
            'am_end_min' => $this->hmToMinutes($eff['am_end_time'] ?? null),
            'pm_start_min' => $this->hmToMinutes($eff['pm_start_time'] ?? null),
            'pm_end_min' => $this->hmToMinutes($eff['pm_end_time'] ?? null),
            'time_in_min' => $this->clockToMinutes($row->time_in),
            'time_out_min' => $this->clockToMinutes($row->time_out),
            'half_day_off' => $halfDayOff,
            'governed_by_early_out' => $governedByEarlyOut,
            // Force-majeure amnesty needs a new hr_daily_overrides.deduction_amnesty column
            // (audit §9) — not built yet, so no day is amnestied. Follow-up before enabling.
            'amnesty' => false,
            'cap_remaining' => $capRemaining,
            'break_minutes' => (int) $row->break_minutes,
            'lunch_minutes' => (int) $row->lunch_minutes,
        ];
    }

    /** 'HH:MM' → minute-of-day, or null. */
    private function hmToMinutes(?string $hm): ?int
    {
        if (! $hm) {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', substr($hm, 0, 5)));

        return $h * 60 + $m;
    }

    /** A stored (UTC) clock time → Manila minute-of-day, or null. */
    private function clockToMinutes(?Carbon $dt): ?int
    {
        if (! $dt) {
            return null;
        }
        $local = $dt->copy()->setTimezone(config('app.timezone_local'));

        return $local->hour * 60 + $local->minute;
    }

    // --- helpers ---

    /**
     * The unpaid fraction of a day for one early-out: undertime (end − time) over the
     * scheduled span (end − start), clamped to [0, 1]. Returns 0.5 when the schedule
     * window is unknown (a sane, non-zero fallback rather than free undertime).
     *
     * @param  array<string, mixed>  $eff
     */
    private function earlyOutFraction(string $time, array $eff): float
    {
        $toMinutes = static function (?string $hm): ?int {
            if (! $hm) {
                return null;
            }
            [$h, $m] = array_map('intval', explode(':', substr($hm, 0, 5)));

            return $h * 60 + $m;
        };

        $start = $toMinutes($eff['start_time'] ?? null);
        $end = $toMinutes($eff['end_time'] ?? null);
        $chosen = $toMinutes($time);

        if ($start === null || $end === null || $chosen === null || $end <= $start) {
            return 0.5;
        }

        $span = $end - $start;
        $undertime = max(0, min($span, $end - $chosen));

        return $undertime / $span;
    }

    /** The schedule's start time as a Carbon on the given local day. */
    private function scheduleStart(Carbon $localDay, array $eff): Carbon
    {
        [$h, $m] = explode(':', $eff['start_time'] ?? self::FALLBACK_START);

        return $localDay->copy()->setTime((int) $h, (int) $m, 0);
    }

    /**
     * Classify an arrival against the schedule: early (before start), on_time
     * (within grace), or late (past grace) with minutes past start.
     *
     * @param  array<string, mixed>  $eff
     * @return array{category:string, late_minutes:int}
     */
    private function arrivalCategory(Carbon $timeInLocal, array $eff, Carbon $dateCarbon): array
    {
        $start = $this->scheduleStart($dateCarbon, $eff);
        $graceEnd = $start->copy()->addMinutes($eff['grace_minutes'] ?? 0);

        if ($timeInLocal->lt($start)) {
            return ['category' => 'early', 'late_minutes' => 0];
        }
        if ($timeInLocal->lte($graceEnd)) {
            return ['category' => 'on_time', 'late_minutes' => 0];
        }

        return ['category' => 'late', 'late_minutes' => (int) $start->diffInMinutes($timeInLocal)];
    }

    /** Employee ids on approved leave covering the date. */
    private function onLeaveIds(string $date): \Illuminate\Support\Collection
    {
        return LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('employee_id')
            ->unique();
    }

    /** employee_id → leave type name, for employees on approved leave that date. */
    private function onLeaveMap(string $date): \Illuminate\Support\Collection
    {
        return LeaveRequest::with('leaveType')
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->get()
            ->keyBy('employee_id')
            ->map(fn ($l) => $l->leaveType?->name);
    }
}
