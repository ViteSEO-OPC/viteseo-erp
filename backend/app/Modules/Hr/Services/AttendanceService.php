<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\OvertimeRequest;
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
        private readonly GeofenceService $geofence,
        private readonly PayrollFreezeService $freeze,
        private readonly RestDayRequestService $restDays,
        private readonly WorkSuspensionService $suspensions,
        private readonly HalfDayWindowService $halfDays,
    ) {}

    /**
     * HR-declared non-working days in an inclusive range, as 'Y-m-d' => is_paid.
     *
     * Thin wrapper so every reader in this file goes through one name, the way
     * nonWorkingHolidays() already does — the service memoizes the query itself.
     *
     * @return array<string, bool>
     */
    private function suspensionsBetween(string $start, string $end): array
    {
        return $this->suspensions->betweenAsMap($start, $end);
    }

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

    /**
     * Per-request memo of one employee's attendance rows for a date range (Slice 15).
     *
     * A payroll run asks the same question four times per employee — unpaidDaysDetailFor,
     * restDayWorkedMinutesFor, nightMinutesFor and overtimeCapacityFor each ran their own
     * `where employee_id = ? and work_date between ? and ?`. The filters differ only in whether
     * they require both punches, which is cheaper to apply in PHP than in a second round trip.
     *
     * @var array<string, \Illuminate\Database\Eloquent\Collection<int, Attendance>>
     */
    private array $rowsMemo = [];

    /**
     * Every attendance row for an employee in an inclusive range, memoized.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Attendance>
     */
    private function rowsBetween(Employee $employee, string $start, string $end)
    {
        return $this->rowsMemo["{$employee->id}|{$start}|{$end}"] ??= Attendance::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start, $end])
            ->get();
    }

    /** Drop the memo after a write, so a correction is never read stale. */
    private function forgetRows(): void
    {
        $this->rowsMemo = [];
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

    /**
     * Refuse anything that assumes the shift is under way while a DEFERRED punch is still
     * in the future (`early_clock_in_mode = defer`: pressed at 07:30, counts from 08:00).
     *
     * Without this, clocking out at 07:45 would produce a row whose time_out precedes its
     * time_in — negative worked minutes, floored to zero, and a day that silently reads as
     * having worked nothing. Better to say plainly that the shift hasn't started.
     *
     * @param  string  $field  the error key the calling action already uses
     */
    private function assertShiftStarted(Attendance $att, string $field): void
    {
        if ($att->time_in === null || ! $att->time_in->isFuture()) {
            return;
        }

        $startsAt = $att->time_in->timezone(config('app.timezone_local'))->format('g:i A');

        throw ValidationException::withMessages([
            $field => ["Your time in is queued for {$startsAt} — your shift hasn't started yet."],
        ]);
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

    /**
     * The employee's own shift start for $local, or null when there is no meaningful one
     * to measure "early" against. Null for two reasons, both of which must stay permissive:
     * today isn't one of their workdays (there is no shift to be early for), or the
     * schedule has no start time at all — FALLBACK_START is a guess, and a guess must never
     * lock someone out of their own time clock, nor silently move their punch.
     *
     * On an approved half day this is the worked half's start, so `block` mode holds an
     * afternoon-only employee to 1:00 PM rather than waving them in at 8:00 AM, and `defer`
     * re-stamps an early punch to the half they are actually there for.
     *
     * @param  array<string, mixed>  $eff  the employee's effective schedule for $local
     * @param  ?string  $offHalf  a HalfDayWindowService::AM|PM|BOTH value, or null for a full day
     */
    private function shiftStartFor(array $eff, Carbon $local, ?string $offHalf = null): ?Carbon
    {
        if (! ($eff['is_workday'] ?? false) || ($eff['start_time'] ?? null) === null) {
            return null;
        }

        return $this->expectedStart($local, $eff, $offHalf);
    }

    /**
     * When today's shift opens for clocking in, or null when nothing holds the employee
     * back. Only `block` mode ever holds anyone back: `defer` deliberately ACCEPTS the
     * early punch (and re-stamps it), so the button must stay live.
     *
     * @param  array<string, mixed>  $eff  the employee's effective schedule for $local
     */
    private function clockInOpensAt(array $eff, Carbon $local, ?string $offHalf = null): ?Carbon
    {
        if ($this->policy->get()->early_clock_in_mode !== 'block') {
            return null;
        }

        return $this->shiftStartFor($eff, $local, $offHalf);
    }

    /**
     * What the employee's time clock needs to know about today beyond their own row.
     * Computed here rather than in the panel so these rules have exactly one reader —
     * a client that re-derived them could offer a button the server would refuse.
     *
     * @return array{mode: string, opens_at: ?string, defers_to: ?string, geofence: bool,
     *               is_workday: bool, rest_day_request: array{id: int, status: string, requested_at: ?string, review_note: ?string}|null,
     *               suspension: array{name: string, is_paid: bool, status: string}|null}
     *                                   both times are Manila "HH:MM"
     */
    public function clockInWindowFor(Employee $employee): array
    {
        $local = $this->localNow();
        $eff = $this->schedule->effectiveFor($employee, $local);
        $mode = $this->policy->get()->early_clock_in_mode;
        // An approved half day moves the shift the panel is describing — telling somebody with
        // the morning off that their shift opens at 8:00 AM is describing a day they don't have.
        $offHalf = $this->halfDays->offHalfFor($employee->id, $local->toDateString());

        // Rest-day work (RD-2): today is not a scheduled workday, so pressing the button files a
        // request instead of punching in. The panel needs BOTH facts — that the day is a rest day,
        // and how the current request stands — because the same button has three meanings
        // depending on the answer, and re-deriving "is today a workday" client-side would put a
        // second, drifting copy of the schedule rules in the browser.
        $isWorkday = (bool) ($eff['is_workday'] ?? true);
        $restDayRequest = $isWorkday || $employee->isManagement()
            ? null
            : $this->restDays->forDate($employee, $local->toDateString());

        // Only meaningful on a day they would otherwise be expected in — telling someone work
        // is cancelled on their rest day is noise.
        $suspension = $isWorkday
            ? $this->suspensions->forDate($local->toDateString())
            : null;

        // Only meaningful right now, and only in defer mode: the time this punch WOULD be
        // recorded at if they pressed the button this second. Null once the shift has
        // started, because from then on the punch is stamped normally.
        $deferTo = $mode === 'defer' ? $this->deferredStartFor($eff, $local, $offHalf) : null;

        return [
            'mode' => $mode,
            'opens_at' => $this->clockInOpensAt($eff, $local, $offHalf)?->format('H:i'),
            'defers_to' => $deferTo?->format('H:i'),
            // Whether the panel should ask WHERE the employee is before clocking in. This
            // is "actually enforcing", not the raw toggle: a policy that is switched on but
            // unconfigured fails open server-side, so making the employee answer a question
            // whose answer will be ignored would be theatre.
            'geofence' => $this->geofence->enforcing(),
            'is_workday' => $isWorkday,
            // Work cancelled today. Deliberately does NOT disable the button: a skeleton crew
            // still comes in during a suspension, and blocking them would leave the people who
            // actually worked with no record of it. The panel says they need not clock in.
            'suspension' => $suspension === null ? null : [
                'name' => $suspension->name,
                'is_paid' => (bool) $suspension->is_paid,
                'status' => $suspension->status(),
            ],
            'rest_day_request' => $restDayRequest === null ? null : [
                'id' => $restDayRequest->id,
                'status' => $restDayRequest->status,
                'requested_at' => $restDayRequest->requested_at?->toIso8601String(),
                'review_note' => $restDayRequest->review_note,
            ],
        ];
    }

    /**
     * In `defer` mode, the shift start an arrival at $local should be re-stamped to — or
     * null when it shouldn't be re-stamped at all (they're on time, or there is no shift
     * to defer to).
     *
     * @param  array<string, mixed>  $eff  the employee's effective schedule for $local
     */
    private function deferredStartFor(array $eff, Carbon $local, ?string $offHalf = null): ?Carbon
    {
        $start = $this->shiftStartFor($eff, $local, $offHalf);

        return $start !== null && $local->lt($start) ? $start : null;
    }

    /**
     * @param  ?float  $lat  browser-captured position; absent is normal and allowed
     * @param  ?float  $accuracy  the reading's radius of confidence, in metres
     * @param  ?string  $ip  the address the SERVER saw, passed in by the controller
     */
    public function clockIn(
        Employee $employee,
        ?float $lat = null,
        ?float $lng = null,
        ?float $accuracy = null,
        ?string $ip = null,
    ): Attendance {
        $this->assertNotManagement($employee);

        $local = $this->localNow();
        // Lateness is measured against the employee's EFFECTIVE schedule (start +
        // grace), resolved from override → assigned → default — not a fixed cutoff.
        $eff = $this->schedule->effectiveFor($employee, $local);

        // Rest-day work (RD-2). A day the schedule does not cover is not the employee's to
        // start: it earns a rest-day premium, so somebody has to agree to spend it. The punch
        // is not lost — RestDayRequestService records this moment and an approval stamps
        // attendance at it — but it is not attendance until then.
        //
        // Deliberately BEFORE the open-shift and already-clocked-in checks: on an approved
        // request the row already exists, and "you have already clocked in today" is the right
        // answer to a second press. This gate is only ever reached without one.
        if (! ($eff['is_workday'] ?? true)) {
            $pending = $this->restDays->forDate($employee, $this->today());

            throw ValidationException::withMessages([
                'time_in' => [
                    $pending?->status === 'pending'
                        ? 'Your request to work today is still with HR — you will be timed in at '
                            . $pending->requested_at?->timezone(config('app.timezone_local'))->format('g:i A')
                            . ', when you asked, once they approve it.'
                        : 'Today is not one of your scheduled workdays. Send HR a request to work it — you will be timed in at the moment you asked, not when they approve.',
                ],
            ]);
        }

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

        // The half of the day this employee has approved off, if any — the shift they are
        // expected for, and therefore the one their arrival is judged against, is the other one.
        $offHalf = $this->halfDays->offHalfFor($employee->id, $this->today());

        // Strict clock-in (Company → Rules): the shift can't be started before it begins.
        $opensAt = $this->clockInOpensAt($eff, $local, $offHalf);
        if ($opensAt && $local->lt($opensAt)) {
            throw ValidationException::withMessages([
                'time_in' => [
                    'Your shift starts at ' . $opensAt->format('g:i A')
                        . ' — you can clock in from then. Ask HR if you need to start earlier.',
                ],
            ]);
        }

        // Geofence (Company → Rules). Returns null — and changes nothing below — whenever the
        // feature is off or unconfigured, so an untouched install behaves exactly as before.
        // Deliberately the LAST gate: no point telling someone they aren't at the office when
        // the real problem is that they already clocked in an hour ago.
        $verifiedBy = $this->geofence->verifyOfficeClockIn($lat, $lng, $accuracy, $ip);

        // `defer` mode: the punch is accepted now but counts from the shift's start. The
        // employee gets to press the button when they walk in and stop thinking about it;
        // the company doesn't pay for the early minutes and nobody is turned away at the
        // door. Null once the shift has started — from then on this is an ordinary punch.
        //
        // Deliberately re-stamped at write time rather than queued for a background job to
        // apply later: the shift start is already known, so the two produce an identical
        // record, and one of them can't fail to run.
        $deferredTo = $this->policy->get()->early_clock_in_mode === 'defer'
            ? $this->deferredStartFor($eff, $local, $offHalf)
            : null;

        // Both branches store UTC. $deferredTo is a Manila-tz Carbon, so it must be
        // converted rather than handed over as-is — Eloquent writes the Carbon's own
        // wall-clock into the column without converting, which would land 8 hours out.
        $att->time_in = $deferredTo?->copy()->utc() ?? now();
        // Judged at the time it will COUNT as, so a deferred punch is never late: the
        // arrival being re-stamped to the shift start is exactly what "on time" means.
        $att->status = $this->arrivalStatus($eff, $deferredTo ?? $local, $offHalf);
        $att->location_verified_by = $verifiedBy;
        $att->save();

        // Slice 16 — a clock-in on an approved paid-leave day gives the credit back, the same
        // settlement an HR correction performs and from the same fact. Payroll already prices
        // this day as worked off `time_in` alone, so without this the employee was paid AND
        // still charged the credit. Safe here: the guard above throws on a second punch, and
        // `leave_credit_refunded_at` makes it idempotent regardless.
        $this->settleLeaveCreditFor($employee, $att);

        return $att;
    }

    /**
     * `present` or `late` for an arrival at $local, judged against the employee's own
     * effective schedule start plus its grace. Shared by the live clock and by HR's
     * approval of a field time-in, so an approved selfie is marked late on exactly the
     * same rule as a punch at the same minute would have been.
     *
     * On an approved half day this is judged against the half they were expected to work, not
     * against the whole shift: somebody with the morning off who walks in at 1:00 PM is on time,
     * and calling that five hours late used to cost them a violation and — with the per-N-lates
     * penalty on — a full day's pay.
     *
     * @param  array<string, mixed>  $eff  the employee's effective schedule for $local
     * @param  ?string  $offHalf  a HalfDayWindowService::AM|PM|BOTH value, or null for a full day
     */
    private function arrivalStatus(array $eff, Carbon $local, ?string $offHalf = null): string
    {
        $start = $this->expectedStart($local, $eff, $offHalf);
        if ($start === null) {
            return 'present'; // the whole day is off — there is no shift to have missed
        }

        $cutoff = $start->addMinutes($eff['grace_minutes'] ?? 0);

        return $local->gt($cutoff) ? 'late' : 'present';
    }

    /**
     * Write a clock-in that a human has already verified, stamped at the moment it was
     * SUBMITTED rather than the moment it was approved.
     *
     * This is the approval half of the off-site selfie path. It deliberately skips the
     * geofence (HR's eyes on the photo *are* the verification) and the strict-clock-in
     * window (HR is overriding, which is the point of a manual review), but it keeps the
     * duplicate guard: two time-ins for one day is a data error whoever creates it.
     *
     * @param  Carbon  $at  when the employee submitted — becomes `time_in` verbatim
     * @param  string  $verifiedBy  an Attendance::LOCATION_* value
     */
    public function recordVerifiedClockIn(Employee $employee, Carbon $at, string $verifiedBy): Attendance
    {
        $this->assertNotManagement($employee);

        // The Manila day the shift STARTED — the same work_date rule the live clock uses,
        // so an 11pm field time-in lands on the day it began, not the day HR approved it.
        $local = $at->copy()->setTimezone(config('app.timezone_local'));

        $att = Attendance::firstOrNew([
            'employee_id' => $employee->id,
            'work_date' => $local->toDateString(),
        ]);

        if ($att->time_in) {
            throw ValidationException::withMessages([
                'time_in' => [
                    'This employee already has a time in for ' . $local->format('M j')
                        . ' — correct that day instead.',
                ],
            ]);
        }

        $eff = $this->schedule->effectiveFor($employee, $local);

        $att->time_in = $at;
        $att->status = $this->arrivalStatus(
            $eff,
            $local,
            $this->halfDays->offHalfFor($employee->id, $local->toDateString()),
        );
        $att->location_verified_by = $verifiedBy;
        $att->save();

        // A new row invalidates any range this request already read.
        $this->forgetRows();

        // Recompute the month this row LANDED in, the way adjust() does — and unlike the
        // live clock, which never needs to. clockIn() can only ever write today, so the
        // default current-month recompute on the next roster read covers it. An approval
        // can write a past day: HR clearing Friday's queue on Monday, or a submission from
        // the tail of last month. Without this, a late field time-in approved after the
        // month turned would raise no flag until someone happened to open that month.
        $this->violations->recompute($local->format('Y-m'));

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

        // A queued (deferred) punch isn't a shift in progress yet.
        $this->assertShiftStarted($att, 'type');

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

        // Clocking out before a deferred time in would invert the row.
        $this->assertShiftStarted($att, 'time_out');

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
        // Item 13b.2 — only the UNPAID meal comes out. A paid break sits inside the paid day
        // by definition, and subtracting it here made `hours_worked` disagree with every other
        // "worked minutes" calculation in the module (restDayWorkedMinutesFor, nightMinutesFor,
        // overtimeCapacityFor all subtract lunch alone).
        $netMinutes = $grossMinutes - $att->lunch_minutes;
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
        // FIN-008 — attendance is the INPUT payroll priced this day from, so it is frozen once
        // that day has been paid.
        //
        // PayrollFreezeService was already injected into the reimbursement, early-out, half-day,
        // overtime and leave services — every path that can change what a period should have paid
        // — but not into this one, which is the most direct of them all. HR could rewrite the
        // attendance behind a disbursed payslip, leaving the record and the payment permanently
        // disagreeing, with the payment being the one that actually happened and no way to pay the
        // difference (FIN-010).
        //
        // Only a LOCKED run or a DISBURSED payslip freezes (see runCovering) — a merely computed
        // period stays editable, because re-running it is the correct way to absorb the change.
        $this->freeze->assertOpen($employee->id, $workDate, null, 'adjust this attendance record');

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

        // Slice 16 — HR may assert the status outright. Left blank it is DERIVED from the times
        // exactly as before (no time-in ⇒ absent; otherwise present vs late against the
        // employee's schedule + grace). Given explicitly it WINS, even against the clock: that is
        // the whole point — "he worked, he just forgot to punch in".
        $override = $data['status'] ?? null;
        if ($override !== null && ! in_array($override, Attendance::HR_STATUSES, true)) {
            $override = null;
        }

        if (empty($data['time_in'])) {
            $att->time_in = null;
            $att->time_out = null;
            $att->hours_worked = 0;
            $att->status = 'absent';
            // The location claim belonged to the punch that has just been removed. Left
            // behind it would read as "absent, but we verified where they were" — a
            // contradiction in the one column an audit is supposed to be able to trust.
            // A CORRECTED time keeps it: HR moving 9:04 to 9:00 doesn't unverify anything.
            $att->location_verified_by = null;
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
            // Same half-day rule the live clock uses. Without it HR "correcting" an afternoon-only
            // day re-stamped it `late`, so the only way to record the truth was the manual status
            // override — a workaround for a derivation that was simply wrong.
            $att->status = $this->arrivalStatus(
                $eff,
                $timeIn,
                $this->halfDays->offHalfFor($employee->id, $workDate),
            );

            if ($att->time_in && $att->time_out) {
                $gross = ($att->time_out->getTimestamp() - $att->time_in->getTimestamp()) / 60;
                // Item 13b.2 — lunch only; a paid break is inside the paid day.
                $net = $gross - $att->lunch_minutes;
                $att->hours_worked = round(max(0, $net) / 60, 2);
            } else {
                $att->hours_worked = 0;
            }
        }

        // Apply HR's choice last, so it overrides whatever the times implied.
        if ($override !== null) {
            $att->status = $override;
        }
        $att->status_override = $override !== null;

        $att->note = $data['note'] ?? null;
        // A human just attested to this row, so it is no longer system-generated —
        // and the nightly management job will now leave it alone.
        $att->source = Attendance::SOURCE_MANUAL;
        $att->save();

        // Slice 16 — a paid-leave day that turns out to have been WORKED gives the credit back.
        // Done here, on a discrete HR action, and stamped: payroll re-runs constantly, so
        // refunding there would inflate the balance a little on every run.
        $this->settleLeaveCreditFor($employee, $att);

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
                'status_override' => (bool) $att->status_override,
                'hours_worked' => (float) $att->hours_worked,
                'note' => $att->note,
            ],
        );

        $this->forgetRows(); // the row just changed

        // Slice 16 — recompute the month the EDIT is in, not "this month". recompute() defaults
        // to the current month, so correcting a June date left June's flags untouched: a late
        // added there raised no flag, and a late removed there kept its old one forever.
        // Attendance is the source of truth for violations, so the flags must follow the edit.
        $this->violations->recompute(Carbon::parse($workDate, $tz)->format('Y-m'));

        return $att;
    }

    /**
     * Give back (or re-take) a paid-leave credit when a leave day turns out to have been worked
     * — or stops having been worked (Slice 16).
     *
     * WHY PER DAY, NOT PER REQUEST. Leave is stored as a date RANGE. Someone who books Mon–Fri
     * and comes in only on Wednesday must get exactly ONE credit back — cancelling the request
     * would refund all five and dock the other four. So this refunds a single day at a time.
     *
     * WHY IDEMPOTENT. `leave_credit_refunded_at` stamps the row, so editing the same day twice
     * can't refund twice, and clearing the attendance puts the credit back where it was.
     *
     * Unpaid leave draws no credit, so there is nothing to refund — only the pay changes, and
     * that is handled in the day loop.
     *
     * RUNS ON THE EMPLOYEE'S OWN CLOCK-IN TOO, not only on an HR correction. Payroll already
     * treats a `time_in` as proof the leave wasn't taken (see the day loop) without anyone
     * approving it, so leaving the credit spent until HR happened to open the row paid the day
     * and charged the credit anyway. The worry in adjust() about refunding from a path that
     * re-runs does not apply here: clockIn() writes once per employee per day and throws on a
     * second punch.
     */
    private function settleLeaveCreditFor(Employee $employee, Attendance $att): void
    {
        $date = $att->work_date->toDateString();

        $leave = LeaveRequest::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        // What this ONE date actually cost the balance at approval — the only amount it can
        // honestly give back.
        $credit = $leave ? $this->creditSpentOn($leave, $att->work_date) : 0.0;

        // Only PAID leave that actually spent something on this date holds a credit worth
        // returning.
        $holdsCredit = $leave && $leave->leaveType && $leave->leaveType->is_paid && $credit > 0;
        // The day counts as worked when the clock says so, or when HR asserts they were in.
        $worked = $att->time_in !== null
            || ($att->status_override && in_array($att->status, ['present', 'late'], true));

        if ($holdsCredit && $worked && $att->leave_credit_refunded_at === null) {
            $this->moveLeaveCredit($leave, -$credit);
            $att->forceFill(['leave_credit_refunded_at' => now()])->save();

            $this->audit->log(
                'updated', 'Leave',
                "Returned {$credit} leave credit — {$date} was worked despite approved "
                    . ($leave->leaveType?->name ?? 'leave'),
                $leave,
            );

            return;
        }

        // The correction was undone (or the leave went away): take the credit back.
        if ($att->leave_credit_refunded_at !== null && (! $holdsCredit || ! $worked)) {
            if ($holdsCredit) {
                $this->moveLeaveCredit($leave, $credit);
            }
            $att->forceFill(['leave_credit_refunded_at' => null])->save();
        }
    }

    /**
     * How much balance ONE date inside a leave request actually consumed at approval.
     *
     * The refund has to mirror LeaveService::computeDays(), which is what spent the credit in
     * the first place. A refund bigger than the deduction invents balance out of nothing, and
     * the `max(0, …)` in moveLeaveCredit() only hides that while the employee has taken no
     * other leave the same year.
     *
     * ZERO FOR A HALF DAY, and not merely because 1.0 ≠ 0.5. On a half day the employee is
     * SUPPOSED to come in and work the other half, so a clock-in there is expected rather than
     * evidence the leave went unused — refunding it would hand back the credit of every half
     * day anyone ever files. The pay side already reasons the same way (see the day loop,
     * where the half-day branch deliberately runs before the clocked-in check).
     *
     * ZERO ON WEEKENDS AND NON-WORKING HOLIDAYS. computeDays() skips them, so a date inside a
     * leave range that happens to be one never cost a credit, and working it cannot return one.
     */
    private function creditSpentOn(LeaveRequest $leave, Carbon $date): float
    {
        // An UNLIMITED type draws no credits at all (`deducted_from = 'none'` — see
        // LeaveService::approve), so there is no balance behind it to give back. Without this
        // the bucket `match` below falls through to its legacy per-type default and decrements
        // a balance the approval never touched.
        if ($leave->deducted_from === 'none') {
            return 0.0;
        }

        if ($leave->is_half_day || $date->isWeekend()) {
            return 0.0;
        }

        if (in_array($date->toDateString(), $this->holidays->nonWorkingDatesBetween($date, $date), true)) {
            return 0.0;
        }

        return 1.0;
    }

    /**
     * Settle every day of a leave request against the attendance that ALREADY exists.
     *
     * WHY. The two facts can arrive in either order. settleLeaveCreditFor() covers "attendance
     * lands on a day already on leave" — a clock-in, or an HR correction. This covers the
     * reverse: leave approved (typically backdated) over days the employee demonstrably worked.
     * Payroll prices those days as worked off `time_in` either way, so without this the approval
     * spent a credit for a day it also paid in full.
     *
     * Delegates per day, so every rule about WHICH days actually hold a credit — half days,
     * weekends, non-working holidays, unlimited types — is stated once, in creditSpentOn().
     */
    public function settleLeaveCreditsFor(LeaveRequest $leave): void
    {
        $employee = $leave->relationLoaded('employee')
            ? $leave->employee
            : Employee::find($leave->employee_id);

        if (! $employee) {
            return;
        }

        $rows = Attendance::where('employee_id', $leave->employee_id)
            ->whereBetween('work_date', [
                $leave->start_date->toDateString(),
                $leave->end_date->toDateString(),
            ])
            ->get();

        foreach ($rows as $att) {
            $this->settleLeaveCreditFor($employee, $att);
        }
    }

    /**
     * How much of a leave request's deduction has already been handed back, day by day.
     *
     * Reversing an approval must give back what the balance is STILL holding, not the request's
     * full `days` — the difference is credit invented from nothing. Recomputed from
     * creditSpentOn() rather than assumed to be 1.0 per stamped row, so the two can never drift.
     */
    public function refundedCreditFor(LeaveRequest $leave): float
    {
        return (float) Attendance::where('employee_id', $leave->employee_id)
            ->whereBetween('work_date', [
                $leave->start_date->toDateString(),
                $leave->end_date->toDateString(),
            ])
            ->whereNotNull('leave_credit_refunded_at')
            ->get()
            ->sum(fn (Attendance $att) => $this->creditSpentOn($leave, $att->work_date));
    }

    /**
     * Drop the refund stamps a leave request left on attendance.
     *
     * For reopen(): the request goes back to pending, so the days it covered stop being leave
     * at all and the stamps stop describing anything. Left behind, a re-approval would deduct
     * the full request again while every stamped row refused to refund a second time (the
     * settlement is idempotent ON that stamp) — quietly charging the employee for days they
     * worked. Cleared, the re-approval settles from scratch and lands in the same place.
     */
    public function clearLeaveCreditStamps(LeaveRequest $leave): void
    {
        Attendance::where('employee_id', $leave->employee_id)
            ->whereBetween('work_date', [
                $leave->start_date->toDateString(),
                $leave->end_date->toDateString(),
            ])
            ->whereNotNull('leave_credit_refunded_at')
            ->update(['leave_credit_refunded_at' => null]);
    }

    /**
     * Shift one leave request's used_days by `$delta`, against the SAME bucket it was deducted
     * from at approval (`deducted_from`) — a mode flip between approve and now must not restore
     * the wrong table. Mirrors LeaveService::cancel()'s reversal.
     */
    private function moveLeaveCredit(LeaveRequest $leave, float $delta): void
    {
        $year = $leave->start_date->year;

        $balance = match ($leave->deducted_from) {
            'pool' => \App\Modules\Hr\Models\SharedLeaveBalance::where('employee_id', $leave->employee_id)
                ->where('year', $year)->first(),
            'per_type' => \App\Modules\Hr\Models\LeaveBalance::where('employee_id', $leave->employee_id)
                ->where('leave_type_id', $leave->leave_type_id)
                ->where('year', $year)->first(),
            // Legacy rows approved before the bucket was stamped: fall back to per-type, which is
            // what a paid type used before shared mode existed.
            default => \App\Modules\Hr\Models\LeaveBalance::where('employee_id', $leave->employee_id)
                ->where('leave_type_id', $leave->leave_type_id)
                ->where('year', $year)->first(),
        };

        $balance?->update(['used_days' => max(0, (float) $balance->used_days + $delta)]);
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
        // Neither is a day HR cancelled — paid or unpaid, nobody was expected in. Counting
        // the whole company "absent" on a typhoon day is the exact failure this prevents.
        $isSuspended = array_key_exists($date, $this->suspensionsBetween($date, $date));
        // Approved half days for the whole roster, memoized by range — the "Late arrivals" tile
        // counted them as lateness, so the number HR reads off the dashboard was inflated by them.
        $offHalves = $this->halfDays->offHalvesBetween($date, $date);

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
                $cat = $this->arrivalCategory(
                    $row->time_in->copy()->setTimezone($tz),
                    $eff,
                    $dateCarbon,
                    $offHalves["{$emp->id}|{$date}"] ?? null,
                )['category'];
                $s[$cat === 'early' ? 'early_arrivals' : ($cat === 'late' ? 'late_arrivals' : 'on_time')]++;
            } elseif (! $isOnLeave && $eff['is_workday'] && ! $isHoliday && ! $isSuspended) {
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
        // HR cancelled work on this date. Null = an ordinary day; true/false = paid/unpaid.
        // Derived here rather than stored per employee, so flipping the declaration moves the
        // whole roster at once instead of leaving rows asserting yesterday's answer.
        $suspendedPaid = $this->suspensionsBetween($date, $date)[$date] ?? null;
        // Approved half days for the whole roster, fetched once rather than per employee: this
        // map is read for every row on the board.
        $offHalves = $this->halfDays->offHalvesBetween($date, $date);

        return Employee::with(['user', 'department', 'workSchedule'])
            ->get()
            ->map(function (Employee $emp) use ($rows, $leaveMap, $flags, $tz, $dateCarbon, $date, $isPast, $isHoliday, $suspendedPaid, $offHalves) {
                $att = $rows->get($emp->id);
                $eff = $this->schedule->effectiveFor($emp, $dateCarbon);
                $leaveName = $leaveMap->get($emp->id);

                // Which half (if any) this employee has approved off — the roster judges
                // punctuality against the half they were expected for, and SHOWS the fact so a
                // 1:00 PM arrival on the board reads as a half day rather than an unexplained one.
                $offHalf = $offHalves["{$emp->id}|{$date}"] ?? null;

                $timing = ($att && $att->time_in)
                    ? $this->arrivalCategory($att->time_in->copy()->setTimezone($tz), $eff, $dateCarbon, $offHalf)
                    : null;

                if ($att && $att->status_override) {
                    $status = $att->status; // Slice 16 — HR asserted this; it wins outright.
                } elseif ($att && $att->time_in) {
                    $status = $att->status; // present | late
                } elseif ($leaveName) {
                    $status = 'on_leave';
                } elseif ($isHoliday && ($eff['is_workday'] ?? false)) {
                    // Non-working holiday on what would have been a workday — not an absence.
                    $status = 'holiday';
                } elseif ($suspendedPaid !== null && ($eff['is_workday'] ?? false)) {
                    // HR cancelled work on what would have been a workday. Ranked BELOW the
                    // holiday branch on purpose: a holiday already pays and already carries a
                    // statutory premium, so a suspension declared over one changes nothing and
                    // must not overwrite the classification payroll prices from.
                    $status = $suspendedPaid ? 'suspended_paid' : 'suspended_unpaid';
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
                    'photo_url' => $emp->photo_url,
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
                    // Lunch is unpaid, so it's part of how hours_worked was reached —
                    // HR's correction dialog needs it to show the same figure.
                    'lunch_minutes' => (int) ($att?->lunch_minutes ?? 0),
                    'break_minutes' => (int) ($att?->break_minutes ?? 0),
                    'hours_worked' => $att ? (float) $att->hours_worked : 0,
                    'status' => $status,
                    // Slice 16 — lets the UI show HR that this status was set by hand.
                    'status_override' => (bool) ($att?->status_override),
                    'leave_type' => $leaveName,
                    // 'AM' | 'PM' | 'BOTH' | null — the half taken OFF (approved half-day request
                    // or half-day leave). The board showed "Late · 5h 0m" with nothing beside it
                    // explaining that the morning was approved off, which is how a correct badge
                    // still misleads the person reading it.
                    'half_day_off' => $offHalf,
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

    /** Fallback allowance when a policy row predates the column (item 5). */
    public const MISSING_TIMEOUT_ALLOWANCE_MIN = 180;

    /**
     * One row's worked window in work-date minute coordinates — the single place that decides
     * what a shift's punches MEAN, including the one that never got closed (item 5).
     *
     * ABANDONED SHIFTS ARE DERIVED, NEVER STAMPED. A row with a time-in and no time-out, whose
     * scheduled end passed more than the policy's allowance ago, resolves to the SCHEDULED END.
     * Nothing is written: deriving is idempotent, cannot rewrite a paid period, and needs no
     * scheduler — which this project does not have. The employee is paid a full ordinary shift
     * including the night differential, and:
     *
     *   • OVERTIME IS STRUCTURALLY ZERO, not merely absent. Overtime is `out − scheduled end`,
     *     and the derived `out` IS the scheduled end, so the subtraction cannot be positive.
     *     That is the safety property: auto-resolution can never manufacture overtime.
     *   • AN APPROVED OT REQUEST DOES NOT EXTEND IT. Approval is permission, not proof — deriving
     *     an end from one would pay overtime nobody clocked, which is the exact failure the
     *     approved-vs-punched overlap match exists to prevent. Such a day belongs in HR's queue.
     *
     * THE ALLOWANCE IS WHAT MAKES THIS SAFE. Without it a mid-cycle preview would resolve an end
     * for someone who is on shift RIGHT NOW and pay hours not yet worked. Past `scheduled end +
     * allowance` they are not still working, they forgot.
     *
     * NO SCHEDULED END, NO DERIVATION: a rest day or non-workday has no shift to be the end of,
     * and an employee with no schedule has nothing to resolve against. Those rows stay unpriced
     * and surface in the review queue instead of being guessed at.
     *
     * @param  array<string, mixed>  $eff  the employee's effective schedule for the row's date
     * @return array{in:int, out:int, derived:bool}|null null when the row cannot be priced
     */
    private function workedWindowFor(Attendance $row, array $eff, ?AttendancePolicy $policy = null, ?string $offHalf = null): ?array
    {
        return $this->resolveWorkedWindow($row, $eff, $policy, $offHalf)['window'];
    }

    /**
     * Why a row resolved the way it did. Every value except `closed` and `auto_closed` describes an
     * open punch the pay paths deliberately refused to price — which is exactly the review queue
     * (item 6). Kept as constants so the queue, the API and the tests cannot drift from the engine.
     */
    public const RESOLVE_NO_TIME_IN = 'no_time_in';

    public const RESOLVE_CLOSED = 'closed';

    public const RESOLVE_AUTO_CLOSED = 'auto_closed';

    public const RESOLVE_REST_DAY = 'rest_day';

    public const RESOLVE_NO_SCHEDULE = 'no_schedule';

    public const RESOLVE_IN_AFTER_END = 'in_after_end';

    public const RESOLVE_WITHIN_ALLOWANCE = 'within_allowance';

    /**
     * `workedWindowFor` with its reasoning exposed.
     *
     * The pay paths only ever need the window, so they use the wrapper above and this stays the one
     * implementation. The review queue needs the DISCARDED branch — which of the refusals fired, and
     * when the cutoff falls — and deriving that a second time somewhere else is how a queue starts
     * disagreeing with the payslip it is supposed to be about.
     *
     * AN APPROVED HALF DAY MOVES THE END. Somebody who worked the morning and forgot to punch
     * out went home at noon, not at 5 — resolving to the full-day end credited them five hours
     * they were not there for, and handed the night-differential window the same five hours to
     * price. The overtime baseline deliberately does NOT move with it (see
     * autoOvertimeMinutesFor): overtime is still measured against the full scheduled end, so
     * working into a half you filed off earns no premium on top of the half-day deduction.
     *
     * @param  array<string, mixed>  $eff  the employee's effective schedule for the row's date
     * @param  ?string  $offHalf  a HalfDayWindowService::AM|PM|BOTH value, or null for a full day
     * @return array{window: array{in:int, out:int, derived:bool}|null, reason: string, cutoff: ?Carbon}
     */
    private function resolveWorkedWindow(Attendance $row, array $eff, ?AttendancePolicy $policy = null, ?string $offHalf = null): array
    {
        $inMin = $this->clockToMinutes($row->time_in);
        if ($inMin === null) {
            return ['window' => null, 'reason' => self::RESOLVE_NO_TIME_IN, 'cutoff' => null];
        }

        $outMin = $this->clockToMinutes($row->time_out);
        if ($outMin !== null) {
            if ($outMin < $inMin) {
                $outMin += 24 * 60; // clocked out after midnight
            }

            return [
                'window' => ['in' => $inMin, 'out' => $outMin, 'derived' => false],
                'reason' => self::RESOLVE_CLOSED,
                'cutoff' => null,
            ];
        }

        // --- open punch: only an ABANDONED one resolves, and only to the scheduled end ---
        if (! ($eff['is_workday'] ?? false)) {
            // rest day — no shift to be the end of
            return ['window' => null, 'reason' => self::RESOLVE_REST_DAY, 'cutoff' => null];
        }

        // The half they were expected to work, or the whole shift on an ordinary day.
        // `pricingWindowFor` rather than `windowFor`: a day with BOTH halves off keeps the full
        // window, because refusing to derive an end there would strand a priceable row in the
        // exception queue instead of merely leaving it unjudged.
        [$startTime, $endTime] = $this->halfDays->pricingWindowFor($eff, $offHalf);

        $startMin = $this->hmToMinutes($startTime);
        $endMin = $this->hmToMinutes($endTime);
        if ($startMin === null || $endMin === null) {
            // no schedule to resolve against
            return ['window' => null, 'reason' => self::RESOLVE_NO_SCHEDULE, 'cutoff' => null];
        }
        if ($endMin < $startMin) {
            $endMin += 24 * 60; // overnight schedule ends on the following day
        }
        if ($endMin <= $inMin) {
            // clocked in at or after their own end — nothing coherent to derive
            return ['window' => null, 'reason' => self::RESOLVE_IN_AFTER_END, 'cutoff' => null];
        }

        $allowance = (int) (($policy ?? $this->policy->get())->missing_timeout_allowance_minutes
            ?? self::MISSING_TIMEOUT_ALLOWANCE_MIN);

        // The cutoff is a wall-clock moment on the row's own date, so a graveyard shift's
        // cutoff lands the following morning without any special-casing here.
        $tz = config('app.timezone_local');
        // `setTimezone` is NOT redundant: Carbon::parse() ignores its $tz argument when handed a
        // Carbon (which the `date` cast makes work_date), so without it the cutoff would be built
        // at midnight UTC and land eight hours late — the whole comparison silently wrong.
        $cutoff = Carbon::parse($row->work_date, $tz)->setTimezone($tz)
            ->startOfDay()
            ->addMinutes($endMin + $allowance);
        if ($this->localNow()->lt($cutoff)) {
            // still within the allowance — they may simply still be working
            return ['window' => null, 'reason' => self::RESOLVE_WITHIN_ALLOWANCE, 'cutoff' => $cutoff];
        }

        return [
            'window' => ['in' => $inMin, 'out' => $endMin, 'derived' => true],
            'reason' => self::RESOLVE_AUTO_CLOSED,
            'cutoff' => $cutoff,
        ];
    }

    /** How far back the review queue looks when the caller does not say. */
    private const OPEN_SHIFT_REVIEW_DAYS = 30;

    /**
     * Open shifts HR should look at — the stale-attendance review queue (item 6).
     *
     * WHAT IT IS FOR. Auto-closing a forgotten punch at the scheduled end (item 5) settles the pay
     * for the ordinary case and nothing else. This lists what is left, and it is deliberately two
     * different kinds of row rather than one undifferentiated pile:
     *
     *   • `needs_fix` — the day CANNOT be priced. A rest day has no shift to be the end of, an
     *     employee with no schedule has nothing to resolve against, and a punch after the person's
     *     own end is incoherent. These days are being paid wrongly RIGHT NOW and only an HR
     *     correction fixes them. This is the whole point of the queue.
     *   • `auto_closed` — the day IS priced, at the scheduled end, and the money is right. The
     *     record is merely still open, which is untidy and leaves the employee's dashboard saying
     *     they are clocked in. Hygiene, not an error.
     *
     * Mixing the two would bury the handful that cost money under the routine ones, which is the
     * failure mode the queue exists to avoid, so the status is on every row and the UI defaults to
     * `needs_fix`.
     *
     * TWO DIFFERENT CLOCKS, deliberately. The policy allowance decides when PAY stops waiting;
     * `MAX_OPEN_SHIFT_HOURS` decides when the EMPLOYEE can no longer close the shift themselves and
     * the correction must come from HR. They answer different questions and are both reported.
     *
     * AN APPROVED OVERTIME REQUEST PROMOTES A ROW. Auto-closure caps the day at the scheduled end,
     * so an approved request on that date pays nothing — by design (approval is permission, not
     * proof). But someone approved it, which is evidence the overtime happened, and that
     * contradiction is exactly what a human should adjudicate. Such a row is never `auto_closed`.
     *
     * NOT LISTED: shifts still inside their allowance. Their cutoff has not passed, so the person
     * may simply still be at work — putting them here would ask HR to chase people who are fine.
     * The window is short and self-clearing: a cutoff sits on the row's own date, so anything older
     * than today has necessarily passed it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function openShiftReview(?string $start = null, ?string $end = null): array
    {
        $tz = config('app.timezone_local');
        $end ??= $this->today();
        $start ??= Carbon::parse($end, $tz)->subDays(self::OPEN_SHIFT_REVIEW_DAYS)->toDateString();

        $rows = Attendance::with(['employee.user', 'employee.department', 'employee.workSchedule'])
            ->whereNotNull('time_in')
            ->whereNull('time_out')
            ->whereBetween('work_date', [$start, $end])
            ->orderBy('work_date')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Approved overtime on the same (employee, date) — the contradiction described above.
        // Cancelled requests are excluded: HR already withdrew the permission.
        $approvedOt = OvertimeRequest::where('status', 'approved')
            ->whereNull('cancelled_at')
            ->whereIn('employee_id', $rows->pluck('employee_id')->unique()->all())
            ->whereBetween('work_date', [$start, $end])
            ->get()
            ->keyBy(fn (OvertimeRequest $r) => $r->employee_id.'|'.$r->work_date->toDateString());

        // The LIVE policy, not the dated version for each row's date. Deliberate: the allowance is
        // an operational "has this person forgotten yet" threshold, not a pay rate, and this list
        // answers "what should HR look at now". Payroll still resolves its own version at cycle end.
        $policy = $this->policy->get();
        $now = $this->localNow();
        // Fetched once for the window: an abandoned punch on an approved half day resolves to
        // that half's end, not to the full shift's.
        $offHalves = $this->halfDays->offHalvesBetween($start, $end);
        $out = [];

        foreach ($rows as $row) {
            // `employee()` is withTrashed, so a separated employee's unresolved day still shows —
            // that is precisely when it matters, since their final pay is about to be computed.
            $employee = $row->employee;
            // Management attendance is synthetic (`hr:generate-management-attendance`) and exempt
            // from the clock entirely, so an open row of theirs is not something HR can act on.
            if (! $employee || $employee->isManagement()) {
                continue;
            }

            $date = $row->work_date->toDateString();
            $eff = $this->schedule->effectiveFor($employee, Carbon::parse($date, $tz));
            $offHalf = $offHalves["{$employee->id}|{$date}"] ?? null;
            $resolved = $this->resolveWorkedWindow($row, $eff, $policy, $offHalf);
            // The window the queue REPORTS has to be the one it resolved against, or a half day
            // shows "scheduled_end 17:00" beside "resolved_time_out 12:00" and reads like a bug.
            [$scheduledStart, $scheduledEnd] = $this->halfDays->pricingWindowFor($eff, $offHalf);

            if ($resolved['reason'] === self::RESOLVE_WITHIN_ALLOWANCE) {
                continue; // they may still be working — not HR's problem yet
            }

            $ot = $approvedOt->get($row->employee_id.'|'.$date);
            $window = $resolved['window'];

            $out[] = [
                'attendance_id' => $row->id,
                'employee_id' => $employee->id,
                'name' => $employee->user?->name,
                'department' => $employee->department?->name,
                'work_date' => $date,
                'time_in' => $row->time_in?->toIso8601String(),
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
                // 'AM' | 'PM' | 'BOTH' | null — why the window above is shorter than the roster's.
                'half_day_off' => $offHalf,
                'reason' => $resolved['reason'],
                // An approval nobody punched for outranks a clean auto-closure: the money is
                // settled but contradicted, which is a decision rather than a tidy-up.
                'status' => ($window !== null && $ot === null) ? 'auto_closed' : 'needs_fix',
                // Where pay put the end of this shift, in the employee's own local clock. Null
                // means pay could not place it at all — the `needs_fix` reasons.
                'resolved_time_out' => $window !== null ? $this->minutesToHm($window['out']) : null,
                'resolved_next_day' => $window !== null && $window['out'] >= 24 * 60,
                'approved_overtime_hours' => $ot !== null ? (float) $ot->hours : null,
                // Two different clocks — see the docblock. Hours open is measured from the punch,
                // not from the scheduled end, because that is what the self-close limit uses.
                'hours_open' => round($row->time_in->copy()->setTimezone($tz)->diffInMinutes($now) / 60, 1),
                'employee_can_fix' => $row->time_in->diffInHours(now()) < self::MAX_OPEN_SHIFT_HOURS,
                'auto_closes_at' => $resolved['cutoff']?->toIso8601String(),
            ];
        }

        // Worst first: unpriced days before tidy-ups, then oldest, so the money-losing rows sit at
        // the top of the table whichever filter HR has on.
        usort($out, function (array $a, array $b) {
            return [$a['status'] === 'auto_closed', $a['work_date']]
                <=> [$b['status'] === 'auto_closed', $b['work_date']];
        });

        return $out;
    }

    /** Statutory fallback; persisted policy defaults use the same 22:00 → 06:00 band. */
    public const NIGHT_START_MIN = 22 * 60;

    public const NIGHT_END_MIN = 6 * 60;

    /**
     * Minutes of a worked window that fall inside the night window (ND-1). Both arguments are
     * minute-of-day on the work date; `out` may exceed 1440 when the shift crosses midnight. In that
     * coordinate space the night bands are [00:00–06:00] of the work date and [22:00 → next 06:00].
     * Pure/static so the overlap arithmetic is unit-testable without a DB.
     */
    public static function nightOverlapMinutes(
        int $in,
        int $out,
        int $nightStart = self::NIGHT_START_MIN,
        int $nightEnd = self::NIGHT_END_MIN,
    ): int {
        if ($out <= $in || $nightStart === $nightEnd) {
            return 0;
        }

        $overlap = static fn (int $a0, int $a1, int $b0, int $b1): int => max(0, min($a1, $b1) - max($a0, $b0));
        $windowEnd = $nightEnd > $nightStart ? $nightEnd : $nightEnd + 24 * 60;
        $firstBand = (int) floor(($in - $windowEnd) / (24 * 60));
        $lastBand = (int) ceil(($out - $nightStart) / (24 * 60));
        $minutes = 0;

        // Repeat the configured daily band across the work-date coordinate space. Starting one
        // band before the work date is what makes an early-morning 04:00 punch match 22:00–06:00.
        for ($band = $firstBand; $band <= $lastBand; $band++) {
            $offset = $band * 24 * 60;
            $minutes += $overlap($in, $out, $nightStart + $offset, $windowEnd + $offset);
        }

        return $minutes;
    }

    /** @return array{0:int, 1:int} */
    public function nightWindow(AttendancePolicy $policy): array
    {
        return [
            $this->hmToMinutes($policy->night_diff_start_time) ?? self::NIGHT_START_MIN,
            $this->hmToMinutes($policy->night_diff_end_time) ?? self::NIGHT_END_MIN,
        ];
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
    public function nightMinutesFor(Employee $employee, string $start, string $end, ?AttendancePolicy $policy = null): array
    {
        if ($employee->isManagement()) {
            return [];
        }

        $tz = config('app.timezone_local');
        $policy ??= $this->policy->get();
        [$nightStart, $nightEnd] = $this->nightWindow($policy);
        // NOT filtered on `time_out`: an abandoned shift resolves to its scheduled end (item 5),
        // and a graveyard worker who forgets to punch out has still worked all night.
        $rows = $this->rowsBetween($employee, $start, $end)->filter(fn (Attendance $r) => (bool) $r->time_in);
        // Memoized by range, so walking employees in a payroll loop costs nothing extra: an
        // approved half day moves where an abandoned punch resolves to.
        $offHalves = $this->halfDays->offHalvesBetween($start, $end);

        $out = [];
        foreach ($rows as $row) {
            $eff = $this->schedule->effectiveFor($employee, Carbon::parse($row->work_date, $tz));
            $window = $this->workedWindowFor($row, $eff, $policy, $offHalves["{$employee->id}|{$row->work_date->toDateString()}"] ?? null);
            if ($window === null) {
                continue;
            }
            [$inMin, $outMin] = [$window['in'], $window['out']];

            $minutes = self::nightOverlapMinutes($inMin, $outMin, $nightStart, $nightEnd);
            if ($minutes > 0) {
                // THE SCHEDULED BREAK IS THE FLOOR, not the recorded one (item 9). A meal period is
                // not work, so it earns no differential — and the day's expected paid minutes are
                // `span − unpaid_break` whether or not anybody pressed the lunch button. Reading
                // only `lunch_minutes` meant a graveyard worker who never toggles lunch collected
                // the premium on a full eight hours while being paid for seven, purely because the
                // button went unpressed. `max()` rather than a straight swap so a LONGER recorded
                // meal is still carved in full.
                $unpaidBreak = max((int) $row->lunch_minutes, (int) ($eff['unpaid_break_minutes'] ?? 0));
                // Carve out only the unpaid meal that the daytime part of the shift can't account for.
                $daytime = ($outMin - $inMin) - $minutes;
                $minutes = max(0, $minutes - max(0, $unpaidBreak - $daytime));
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
     * ─────────────────────────────────────────────────────────────────────────────────────────
     * DO NOT "FIX" THE LATE-ARRIVAL CASE BY NETTING. Measuring from `end_time` is deliberate.
     *
     * Arrive an hour late, leave an hour past the shift, and this pays 60 minutes of overtime
     * while the late hour is separately docked. That looks like the employee profits from being
     * late, and it is tempting to subtract one from the other. Two settled rulings say no:
     *
     *   • Rule 2e — overtime is measured against `end_time`, NEVER against hours worked:
     *         overtimeMinutes = max(0, time_out − end_time)     ✅
     *         overtimeMinutes = hoursWorked − expectedHours     ❌ never
     *     Someone who works 08:00–18:00 straight through their meal has a 10-hour clock reading
     *     and ZERO overtime, because they left at their scheduled end. Working through lunch is
     *     not the same as working late.
     *
     *   • Finding F-4 — `netOvertime = minutesPastEnd − lateMinutes − undertimeMinutes` is
     *     NON-COMPLIANT. Labor Code Art. 88 forbids offsetting undertime with overtime, because
     *     overtime carries a ≥125% premium while undertime is docked at the plain rate — netting
     *     shortchanges the worker. The deduction ledger and the OT ledger stay separate.
     *
     * Note that `workedMinutes − expectedMinutes` is NOT a third option: with the meal equal to
     * the scheduled break it reduces algebraically to `minutesPastEnd − lateMinutes`, i.e. exactly
     * the netting F-4 rejected; and with the RECORDED lunch it manufactures overtime out of a
     * skipped meal, which is the case 2e exists to prevent.
     *
     * The answer to the apparent "profit" is that `approved_only` is the default: unapproved
     * late-staying simply isn't overtime, so there is no premium to game. Auto mode remains
     * available for companies that want it, and the Rules page warns about exactly this.
     * ─────────────────────────────────────────────────────────────────────────────────────────
     *
     * @return array<string, array{minutes:int, night:int}> date 'Y-m-d' => overtime minutes
     */
    public function autoOvertimeMinutesFor(
        Employee $employee,
        string $start,
        string $end,
        int $thresholdMinutes = 0,
        int $dailyCapMinutes = 0,
        ?AttendancePolicy $policy = null,
    ): array {
        if ($employee->isManagement()) {
            return [];
        }

        $tz = config('app.timezone_local');
        $policy ??= $this->policy->get();
        [$nightStart, $nightEnd] = $this->nightWindow($policy);
        $rows = $this->rowsBetween($employee, $start, $end)->filter(fn (Attendance $r) => (bool) $r->time_in);
        // Memoized by range, so walking employees in a payroll loop costs nothing extra: an
        // approved half day moves where an abandoned punch resolves to.
        $offHalves = $this->halfDays->offHalvesBetween($start, $end);

        $out = [];
        foreach ($rows as $row) {
            $day = Carbon::parse($row->work_date, $tz);
            $eff = $this->schedule->effectiveFor($employee, $day);
            if (! ($eff['is_workday'] ?? false)) {
                continue; // rest day — see the note above
            }

            $startMin = $this->hmToMinutes($eff['start_time'] ?? null);
            $endMin = $this->hmToMinutes($eff['end_time'] ?? null);
            // An abandoned shift resolves to the scheduled end, so `out − end` below is exactly
            // zero and no overtime can be manufactured out of a missing punch (item 5).
            $window = $this->workedWindowFor($row, $eff, $policy, $offHalves["{$employee->id}|{$row->work_date->toDateString()}"] ?? null);
            if ($startMin === null || $endMin === null || $window === null) {
                continue;
            }
            [$inMin, $outMin] = [$window['in'], $window['out']];
            if ($endMin < $startMin) {
                $endMin += 24 * 60; // overnight schedule ends on the following day
            }

            $minutes = $outMin - $endMin;
            if ($minutes > 0 && $minutes >= max(0, $thresholdMinutes)) {
                // FIN-039 — the daily cap is applied HERE, not by the caller, because this is the only
                // place that knows where the overtime window begins. Night differential is the reason
                // it matters: capping 300 minutes to 240 has to mean "the first four hours after the
                // shift", so the night portion is recomputed over the shortened window. Trimming the
                // total afterwards and leaving `night` alone would pay night premium on minutes that
                // were never paid as overtime.
                $out[$day->toDateString()] = self::autoOvertimeEntry(
                    $endMin,
                    $minutes,
                    $dailyCapMinutes > 0 ? $dailyCapMinutes : null,
                    $nightStart,
                    $nightEnd,
                );
            }
        }

        return $out;
    }

    /**
     * One date's auto overtime, split into what pays automatically and what needs a human (FIN-039).
     *
     * `minutes` is what the run pays; `excess_minutes` is what the cap held back, which is not lost —
     * it goes to the review queue and pays in full once an overtime request for that date is approved.
     * `window_start` is carried so the period cap can recompute the night portion just as precisely.
     *
     * `$capMinutes` is NULLABLE, and null is not the same as 0: null means uncapped, 0 means nothing
     * pays automatically. The policy column stores 0 for uncapped (its natural "unset" value), so the
     * translation happens at the call site — a zero budget left over from the period cap must trim the
     * day to nothing rather than waving it through.
     *
     * @return array{minutes:int, night:int, raw_minutes:int, excess_minutes:int, window_start:int}
     */
    private static function autoOvertimeEntry(
        int $windowStart,
        int $rawMinutes,
        ?int $capMinutes,
        int $nightStart = self::NIGHT_START_MIN,
        int $nightEnd = self::NIGHT_END_MIN,
    ): array {
        $paid = $capMinutes === null ? $rawMinutes : max(0, min($rawMinutes, $capMinutes));

        return [
            'minutes' => $paid,
            'night' => self::nightOverlapMinutes($windowStart, $windowStart + $paid, $nightStart, $nightEnd),
            'raw_minutes' => $rawMinutes,
            'excess_minutes' => max(0, $rawMinutes - $paid),
            'window_start' => $windowStart,
        ];
    }

    /**
     * Release the punch-backed part of a capped auto-OT entry that an approval authorises.
     * Exact windows remain exact; legacy hours-only approvals retain their total-based fallback.
     *
     * @param  array{minutes:int, night:int, raw_minutes:int, excess_minutes:int, window_start:int}  $entry
     * @param  array{minutes:int, night:int, windows:array<int, array{start:int, end:int}>, legacy_minutes:int}|null  $approval
     * @return array{minutes:int, night:int, raw_minutes:int, excess_minutes:int, window_start:int}
     */
    public static function releaseApprovedOvertimeEntry(
        array $entry,
        ?array $approval,
        int $nightStart = self::NIGHT_START_MIN,
        int $nightEnd = self::NIGHT_END_MIN,
    ): array {
        if ($approval === null || $entry['excess_minutes'] <= 0) {
            return $entry;
        }

        $heldStart = $entry['window_start'] + $entry['minutes'];
        $heldEnd = $entry['window_start'] + $entry['raw_minutes'];
        $exact = [];

        foreach ($approval['windows'] as $window) {
            $start = max($heldStart, $window['start']);
            $end = min($heldEnd, $window['end']);
            if ($end > $start) {
                $exact[] = ['start' => $start, 'end' => $end];
            }
        }
        $exact = self::mergeMinuteWindows($exact);

        $exactMinutes = array_sum(array_map(
            static fn (array $window): int => $window['end'] - $window['start'],
            $exact,
        ));

        if ($approval['windows'] !== []) {
            // Alongside exact approvals, an hours-only row authorises additional held capacity.
            $legacyTarget = min(
                max(0, $entry['excess_minutes'] - $exactMinutes),
                $approval['legacy_minutes'],
            );
        } else {
            // Preserve the historical hours-only meaning: the approval is a total for the day.
            $legacyTarget = min(
                $entry['excess_minutes'],
                max(0, $approval['minutes'] - $entry['minutes']),
            );
        }

        $released = self::mergeMinuteWindows([
            ...$exact,
            ...self::fillMinuteWindowGaps($heldStart, $heldEnd, $exact, $legacyTarget),
        ]);
        $release = min(
            $entry['excess_minutes'],
            array_sum(array_map(
                static fn (array $window): int => $window['end'] - $window['start'],
                $released,
            )),
        );

        if ($release <= 0) {
            return $entry;
        }

        $entry['minutes'] += $release;
        $entry['night'] += array_sum(array_map(
            static fn (array $window): int => self::nightOverlapMinutes(
                $window['start'],
                $window['end'],
                $nightStart,
                $nightEnd,
            ),
            $released,
        ));
        $entry['excess_minutes'] -= $release;

        return $entry;
    }

    /** Held back by a cap: it does NOT pay until an overtime request for that date is approved. */
    public const OT_REVIEW_CAP = 'cap';

    /** More overtime in one day than the review threshold. It pays; somebody should look at it. */
    public const OT_REVIEW_LONG = 'long_overtime';

    /** The same day was both late and overtime. Both are paid/charged in full — Art. 88. */
    public const OT_REVIEW_LATE = 'late_and_overtime';

    /**
     * Auto-mode overtime that wants a human's attention (FIN-039, extended by item 7).
     *
     * TWO KINDS OF ROW, and the difference is what INACTION costs:
     *
     *   • `held` (reason `cap`) — a cap withheld these minutes and they do not pay until an overtime
     *     request for that date is approved. Doing nothing means the employee is not paid.
     *   • `flagged` (reasons `long_overtime` / `late_and_overtime`) — the day pays in full, already,
     *     automatically. It is listed because it looks unusual. Doing nothing means it pays anyway.
     *
     * They must never be presented as one list: the first is a decision that withholds someone's
     * money, the second is a notification, and a reader who confuses them will either sit on wages or
     * assume a payment is still pending. `kind` exists so the UI cannot blur them.
     *
     * WHY THE FLAGGED KIND EXISTS AT ALL. Both caps ship as 0 and this used to return nothing in that
     * configuration, so a stock install reviewed no overtime whatsoever — and 0 does not mean "no
     * overtime", it means UNLIMITED. A clock-out corrected to the next morning paid nine hours at
     * 125% with nobody notified. The review threshold is the version of a cap that cannot underpay
     * anyone: it raises a flag and touches no money.
     *
     * DERIVED ON READ, and writing nothing, which is the whole design. The obvious alternative — having
     * the payroll run file a pending overtime request for each over-cap day — is the FIN-038 mistake:
     * a run that mutates the records it reads stops being safe to re-run, and a preview would start
     * creating requests. Here the queue is a question asked of attendance, so it answers the same way
     * however many times HR opens it, and re-running payroll changes nothing.
     *
     * An APPROVAL REDUCES OR REMOVES A HELD ITEM rather than resolving it, for the same reason. There
     * is no separate state to clear: only the approved, punch-backed overlap stops being reported. HR's
     * action is the existing approve button on the existing queue, not a new verb. A FLAGGED row has
     * nothing to approve — it is already paying — so it stays until the attendance behind it changes.
     *
     * Still empty while `overtime_mode` is not `auto_after_threshold`: nothing is paid from the clock
     * alone in those modes, so there is nothing here that a human did not already authorise.
     *
     * @return list<array{employee_id:int, employee_no:string|null, name:string|null, work_date:string,
     *                    paid_minutes:int, excess_minutes:int, raw_minutes:int, approved_minutes:int,
     *                    late_minutes:int, kind:string, reasons:list<string>}>
     */
    public function autoOvertimeReview(string $start, string $end): array
    {
        $policy = $this->policy->get();

        $dailyCap = (int) $policy->auto_overtime_daily_cap_minutes;
        $periodCap = (int) $policy->auto_overtime_period_cap_minutes;
        $reviewMinutes = (int) ($policy->auto_overtime_review_minutes ?? 0);
        [$nightStart, $nightEnd] = $this->nightWindow($policy);

        // NOT also gated on the caps any more — that gate is exactly what made the queue silent on
        // the shipped configuration. The mode gate stays: in `approved_only` and `none` every paid
        // overtime minute was authorised by a person, so there is nothing to review.
        if ($policy->overtime_mode !== 'auto_after_threshold') {
            return [];
        }

        // Preload approved requests once; each employee is matched against their punch-backed windows.
        $approved = OvertimeRequest::where('status', 'approved')
            ->whereBetween('work_date', [$start, $end])
            ->get(['employee_id', 'work_date', 'hours', 'time_from', 'time_to'])
            ->groupBy('employee_id');

        $out = [];

        // `withTrashed()` for the same reason the run itself uses it: someone who left mid-cycle still
        // has days in it, and their overtime still has to be reviewable.
        $employees = Employee::withTrashed()->with('user')->get();

        foreach ($employees as $employee) {
            $map = self::applyAutoOvertimePeriodCap(
                $this->autoOvertimeMinutesFor(
                    $employee,
                    $start,
                    $end,
                    (int) $policy->overtime_threshold_minutes,
                    $dailyCap,
                    $policy,
                ),
                $periodCap,
                $nightStart,
                $nightEnd,
            );

            if ($map === []) {
                continue;
            }

            $anyExcess = array_filter($map, fn (array $entry) => ($entry['excess_minutes'] ?? 0) > 0) !== [];

            // Only worth deriving when it can change an answer: an approval either releases held
            // minutes or supplies the "of which N were approved" figure on a flagged row.
            $approvals = ($anyExcess || $approved->has($employee->id))
                ? $this->approvedOvertimeMinutesFor(
                    $employee,
                    $start,
                    $end,
                    $approved->get($employee->id) ?? new Collection,
                    $policy,
                )
                : [];

            // Lateness from the punches, NOT from the deduction ledger — see lateMinutesFor().
            $lateByDate = $this->lateMinutesFor($employee, $start, $end);

            foreach ($map as $date => $entry) {
                $approval = $approvals[$date] ?? null;
                $released = self::releaseApprovedOvertimeEntry(
                    $entry,
                    $approval,
                    $nightStart,
                    $nightEnd,
                );
                $stillHeld = (int) $released['excess_minutes'];
                $rawMinutes = (int) ($entry['raw_minutes'] ?? $entry['minutes']);
                $lateMinutes = (int) ($lateByDate[$date] ?? 0);

                $reasons = [];
                if ($stillHeld > 0) {
                    // Anything already approved has stopped being held, so it is not a queue item.
                    $reasons[] = self::OT_REVIEW_CAP;
                }
                // Measured against the RAW overrun, not the capped figure: the question is how long
                // the person stayed, which is what looks wrong, and a cap trimming the paid number
                // does not make a nine-hour overrun ordinary. 0 disables the flag.
                if ($reviewMinutes > 0 && $rawMinutes >= $reviewMinutes) {
                    $reasons[] = self::OT_REVIEW_LONG;
                }
                // Art. 88 forbids netting the two, so this day both docks the late minutes AND pays
                // the overtime at a premium. That is correct and it surprises people, so show it.
                if ($lateMinutes > 0 && $rawMinutes > 0) {
                    $reasons[] = self::OT_REVIEW_LATE;
                }

                if ($reasons === []) {
                    continue;
                }

                $out[] = [
                    'employee_id' => $employee->id,
                    'employee_no' => $employee->employee_no,
                    'name' => $employee->user?->name,
                    'work_date' => $date,
                    'paid_minutes' => (int) $released['minutes'],
                    'excess_minutes' => $stillHeld,
                    'raw_minutes' => $rawMinutes,
                    'approved_minutes' => (int) ($approval['minutes'] ?? 0),
                    'late_minutes' => $lateMinutes,
                    // `cap` decides the kind whenever it is present: money being withheld outranks a
                    // notification, because it is the only one of the two that HR must act on.
                    'kind' => $stillHeld > 0 ? 'held' : 'flagged',
                    'reasons' => $reasons,
                ];
            }
        }

        // Held money first, then the largest overrun. Sorting purely by size would let a nine-hour
        // flagged day that is already paid outrank a two-hour one that is not being paid at all.
        usort($out, fn (array $a, array $b) => [$a['kind'] === 'flagged', -$a['excess_minutes'], -$a['raw_minutes']]
            <=> [$b['kind'] === 'flagged', -$b['excess_minutes'], -$b['raw_minutes']]);

        return $out;
    }

    /**
     * Late minutes per date, measured from the scheduled start once the grace period is exhausted.
     *
     * DELIBERATELY NOT the deduction ledger's figure. DeductionService reports 0 late minutes
     * whenever `late_deduction_mode` is `none`, because there it is answering "how much do we
     * charge for this". The question here is "did this person arrive late", which is true or false
     * regardless of whether the company charges for it — the review queue has to flag the
     * late-plus-overtime day at a company that never docks anyone, since the surprising half of
     * that pairing is the overtime being paid, not the lateness being charged.
     *
     * Grace is honoured so a two-minute arrival inside a fifteen-minute grace is not "late"; the
     * minutes reported are counted from the scheduled start, matching the roster's own badge.
     *
     * @return array<string, int> date 'Y-m-d' => late minutes, omitted on days that were not late
     */
    public function lateMinutesFor(Employee $employee, string $start, string $end): array
    {
        if ($employee->isManagement()) {
            return []; // exempt from the clock entirely
        }

        $tz = config('app.timezone_local');
        $out = [];
        // Memoized by range. This feeds the overtime review queue's "late AND overtime" reason,
        // which was firing on afternoons the employee had legitimately been given off.
        $offHalves = $this->halfDays->offHalvesBetween($start, $end);

        foreach ($this->rowsBetween($employee, $start, $end) as $row) {
            if (! $row->time_in) {
                continue;
            }

            // `->toDateString()` is NOT redundant. Carbon::parse() IGNORES its $tz argument when
            // handed a DateTimeInterface, which the `date` cast makes work_date — so parsing the
            // Carbon directly builds midnight UTC, and expectedStart() below would place an 08:00
            // shift at 16:00 Manila. Every arrival would then look early and nobody would ever be
            // late. Round-tripping through the string is what makes the timezone apply.
            $day = Carbon::parse($row->work_date->toDateString(), $tz);
            $eff = $this->schedule->effectiveFor($employee, $day);
            // No scheduled start to be late for. The null check is not redundant with the workday
            // one: expectedStart() falls back to 09:00 for a missing time, and inventing a baseline
            // would report an employee with no schedule at all as late every morning.
            if (! ($eff['is_workday'] ?? false) || ($eff['start_time'] ?? null) === null) {
                continue;
            }

            $arrival = $this->arrivalCategory(
                $row->time_in->copy()->setTimezone($tz),
                $eff,
                $day,
                $offHalves["{$employee->id}|{$day->toDateString()}"] ?? null,
            );
            if (($arrival['late_minutes'] ?? 0) > 0) {
                $out[$day->toDateString()] = (int) $arrival['late_minutes'];
            }
        }

        return $out;
    }

    /**
     * Undertime minutes per date — how far short of the expected end the employee clocked out.
     *
     * DELIBERATELY NOT the deduction ledger's figure, for the same reason lateMinutesFor() is not:
     * DeductionService reports 0 undertime whenever `undertime_deduction_mode` is `none`, because
     * there it is answering "how much do we charge for this". The question here is "did this person
     * leave early", which is true or false whether or not the company docks anyone for it — an
     * employee reading their own month has to see the shortfall even at a company that never
     * charges it.
     *
     * The expected end is the HALF actually owed on a day with an approved half-day (mirroring
     * DeductionService::workedWindow via HalfDayWindowService), a whole day off is skipped, and a
     * missing clock-out is skipped rather than treated as leaving at midnight — an abandoned punch
     * is auto-closed at the scheduled end (item 6), never undertime.
     *
     * @return array<string, int> date 'Y-m-d' => undertime minutes, omitted on days that ran full
     */
    public function undertimeMinutesFor(Employee $employee, string $start, string $end): array
    {
        if ($employee->isManagement()) {
            return []; // exempt from the clock entirely
        }

        $tz = config('app.timezone_local');
        $out = [];
        // Memoized by range, like every other per-day map here.
        $offHalves = $this->halfDays->offHalvesBetween($start, $end);

        foreach ($this->rowsBetween($employee, $start, $end) as $row) {
            if (! $row->time_in || ! $row->time_out) {
                continue;
            }

            // `->toDateString()` is NOT redundant — see lateMinutesFor() for why parsing the
            // `date`-cast Carbon directly would build midnight UTC and move the whole shift.
            $day = Carbon::parse($row->work_date->toDateString(), $tz);
            $eff = $this->schedule->effectiveFor($employee, $day);
            if (! ($eff['is_workday'] ?? false)) {
                continue; // a rest day has no scheduled end to fall short of
            }

            $offHalf = $offHalves["{$employee->id}|{$day->toDateString()}"] ?? null;
            [$startHm, $endHm] = $this->halfDays->windowFor($eff, $offHalf);
            $startMin = $this->hmToMinutes($startHm);
            $endMin = $this->hmToMinutes($endHm);
            if ($startMin === null || $endMin === null) {
                continue; // whole day off, or no window configured — nothing to measure against
            }

            $outMin = $this->clockToMinutes($row->time_out);

            // Item 15 — an overnight shift needs the end and any post-midnight punch rolled past
            // 24h before these minute-of-day values can be compared, or a 05:00 finish on a
            // 22:00–06:00 shift reads as sixteen hours of undertime instead of one.
            if ($endMin < $startMin) {
                $boundary = intdiv($endMin + $startMin, 2);
                if ($outMin !== null && $outMin <= $boundary) {
                    $outMin += 24 * 60;
                }
                $endMin += 24 * 60;
            }

            $minutes = max(0, $endMin - (int) $outMin);
            if ($minutes > 0) {
                $out[$day->toDateString()] = $minutes;
            }
        }

        return $out;
    }

    /**
     * The cycle-wide ceiling on auto overtime, applied after the daily one (FIN-039).
     *
     * Spent CHRONOLOGICALLY. The earliest dates in the cycle consume the budget and later ones fall to
     * the review queue, because that is the only allocation an employee can predict while the month is
     * still running: on the 3rd you either were inside the budget or you weren't, and nothing that
     * happens on the 20th can retroactively take it away. Spending the budget on the largest days, or
     * prorating it across the cycle, would make each day's pay a function of the whole month.
     *
     * @param  array<string, array<string, int>>  $map
     * @return array<string, array<string, int>>
     */
    public static function applyAutoOvertimePeriodCap(
        array $map,
        int $periodCapMinutes,
        int $nightStart = self::NIGHT_START_MIN,
        int $nightEnd = self::NIGHT_END_MIN,
    ): array {
        if ($periodCapMinutes <= 0 || $map === []) {
            return $map; // 0 = uncapped
        }

        ksort($map); // date keys — chronological, see above
        $budget = $periodCapMinutes;

        foreach ($map as $date => $entry) {
            $allowed = max(0, min((int) $entry['minutes'], $budget));
            $budget -= $allowed;

            // Re-derive from the RAW figure so the two caps compose instead of stacking: a day already
            // trimmed 300→240 daily and then 240→60 here must report 240 held back, not 180.
            $map[$date] = self::autoOvertimeEntry(
                (int) ($entry['window_start'] ?? 0),
                (int) ($entry['raw_minutes'] ?? $entry['minutes']),
                $allowed, // a real budget, 0 included — see the null-vs-0 note on autoOvertimeEntry()
                $nightStart,
                $nightEnd,
            );
        }

        return $map;
    }

    /**
     * The punch-backed overtime interval on each date, in work-date minute coordinates. The end
     * may exceed 1440 when work crosses midnight. A rest-day interval contains only the tail after
     * eight worked hours and the unpaid meal; the earlier block is rest-day premium time.
     *
     * @return array<string, array{start:int, end:int}>
     */
    public function overtimeWindowsFor(Employee $employee, string $start, string $end): array
    {
        if ($employee->isManagement()) {
            return [];
        }

        $tz = config('app.timezone_local');
        $rows = $this->rowsBetween($employee, $start, $end)->filter(fn (Attendance $r) => (bool) $r->time_in);
        // Memoized by range, so walking employees in a payroll loop costs nothing extra: an
        // approved half day moves where an abandoned punch resolves to.
        $offHalves = $this->halfDays->offHalvesBetween($start, $end);

        $windows = [];
        foreach ($rows as $row) {
            $day = Carbon::parse($row->work_date, $tz);
            $eff = $this->schedule->effectiveFor($employee, $day);

            // An abandoned shift resolves to the scheduled end (item 5), which makes the interval
            // below empty — a missing punch can never back an overtime window.
            $window = $this->workedWindowFor($row, $eff, null, $offHalves["{$employee->id}|{$row->work_date->toDateString()}"] ?? null);
            if ($window === null) {
                continue;
            }
            [$inMin, $outMin] = [$window['in'], $window['out']];

            if ($eff['is_workday'] ?? false) {
                $startMin = $this->hmToMinutes($eff['start_time'] ?? null);
                $endMin = $this->hmToMinutes($eff['end_time'] ?? null);
                if ($startMin === null || $endMin === null) {
                    continue;
                }
                if ($endMin < $startMin) {
                    $endMin += 24 * 60;
                }
                $windowStart = max($endMin, $inMin);
            } else {
                $worked = ($outMin - $inMin) - (int) $row->lunch_minutes;
                $capacity = $worked - self::REST_DAY_PREMIUM_CAP_MIN;
                $windowStart = $outMin - max(0, $capacity);
            }

            if ($outMin > $windowStart) {
                $windows[$day->toDateString()] = ['start' => $windowStart, 'end' => $outMin];
            }
        }

        return $windows;
    }

    /**
     * Approved overtime as the exact intersection of approved and punch-backed windows.
     *
     * @param  Collection<int, OvertimeRequest>|null  $requests
     * @return array<string, array{minutes:int, night:int, windows:array<int, array{start:int, end:int}>, legacy_minutes:int}>
     */
    public function approvedOvertimeMinutesFor(
        Employee $employee,
        string $start,
        string $end,
        ?Collection $requests = null,
        ?AttendancePolicy $policy = null,
    ): array {
        $requests ??= OvertimeRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereBetween('work_date', [$start, $end])
            ->get(['work_date', 'hours', 'time_from', 'time_to']);

        if ($requests->isEmpty()) {
            return [];
        }

        [$nightStart, $nightEnd] = $this->nightWindow($policy ?? $this->policy->get());
        $clocked = $this->overtimeWindowsFor($employee, $start, $end);
        $approvedWindows = [];
        $legacyMinutes = [];

        foreach ($requests as $request) {
            $date = $request->work_date->toDateString();
            $actual = $clocked[$date] ?? null;
            if ($actual === null) {
                continue;
            }

            if ($request->time_from && $request->time_to) {
                $from = $this->hmToMinutes((string) $request->time_from);
                $to = $this->hmToMinutes((string) $request->time_to);
                if ($to <= $from) {
                    $to += 24 * 60;
                }

                $overlap = self::bestWindowOverlap($from, $to, $actual['start'], $actual['end']);
                if ($overlap !== null) {
                    $approvedWindows[$date][] = $overlap;
                }

                continue;
            }

            $legacyMinutes[$date] = ($legacyMinutes[$date] ?? 0)
                + (int) round((float) $request->hours * 60);
        }

        $out = [];
        foreach ($clocked as $date => $actual) {
            $minutes = 0;
            $night = 0;
            $windows = self::mergeMinuteWindows($approvedWindows[$date] ?? []);
            foreach ($windows as $window) {
                $minutes += $window['end'] - $window['start'];
                $night += self::nightOverlapMinutes($window['start'], $window['end'], $nightStart, $nightEnd);
            }

            $capacity = $actual['end'] - $actual['start'];
            $legacy = min(max(0, $capacity - $minutes), $legacyMinutes[$date] ?? 0);
            if ($legacy > 0) {
                // Item 9 — an hours-only approval CAN prove a night portion after all. It has no
                // clock window of its own, but the punch-backed overtime interval is known, so the
                // approved hours are placed inside it and their night overlap read off. Reporting
                // night = 0 sent those minutes down the plain-differential path, paying
                // hourly × 10% for work that had earned hourly × OT% × 10%.
                $night += self::legacyNightMinutes($actual, $windows, $legacy, $nightStart, $nightEnd);
            }
            $minutes += $legacy;

            if ($minutes > 0) {
                $out[$date] = [
                    'minutes' => $minutes,
                    'night' => min($night, $minutes),
                    'windows' => $windows,
                    'legacy_minutes' => $legacy,
                ];
            }
        }

        return $out;
    }

    /**
     * Night minutes an hours-only ("legacy") approval earns, by placing its hours inside the
     * overtime the employee actually clocked.
     *
     * CHRONOLOGICAL, EARLIEST FIRST, and that choice is the whole answer. Overtime accrues from the
     * scheduled end forward, so an approval covering 2 of the 6 hours clocked authorises the FIRST
     * two — "you may stay until 20:00", not "you may stay from midnight". It also matches how every
     * other partial allocation in this module already behaves: `autoOvertimeEntry` caps a day from
     * its window start, and the period cap is spent oldest-date first. The alternative, back-loading
     * the hours to maximise the night overlap, would pay the most for the least evidence.
     *
     * Minutes already covered by exact approvals are skipped — those windows counted their own night
     * portion, and counting them twice would inflate the differential.
     *
     * @param  array{start:int, end:int}  $actual  the punch-backed overtime interval
     * @param  array<int, array{start:int, end:int}>  $windows  merged exact approvals, sorted
     */
    private static function legacyNightMinutes(
        array $actual,
        array $windows,
        int $minutes,
        int $nightStart,
        int $nightEnd,
    ): int {
        if ($minutes <= 0) {
            return 0;
        }

        $night = 0;
        $cursor = $actual['start'];
        $remaining = $minutes;

        // Walk the gaps between the exact windows, then the tail after the last one.
        foreach ([...$windows, ['start' => $actual['end'], 'end' => $actual['end']]] as $covered) {
            if ($remaining <= 0) {
                break;
            }

            $gapEnd = min($covered['start'], $actual['end']);
            if ($gapEnd > $cursor) {
                $take = min($remaining, $gapEnd - $cursor);
                $night += self::nightOverlapMinutes($cursor, $cursor + $take, $nightStart, $nightEnd);
                $remaining -= $take;
            }

            $cursor = max($cursor, min($covered['end'], $actual['end']));
        }

        return $night;
    }

    /**
     * Everything about this cycle's attendance that a human should see BEFORE payroll prices it
     * (item 12).
     *
     * WHY A SUMMARY AND NOT A SIXTH QUEUE. Every check below already exists and already has a
     * screen — open shifts on Attendance, held and flagged overtime on the Overtime page. What did
     * not exist was anyone asking them at the moment it matters. HR runs payroll from a different
     * page entirely, so the queues were only found by whoever happened to visit; this puts the
     * counts in front of the person about to turn attendance into money.
     *
     * COUNTS AND A DESTINATION, NOT ROWS. Each entry says how many and where to fix them, and the
     * fixing happens on the screen that owns it. Restating the rows here would be a second place to
     * keep in step with the first, and the queues already explain their own consequences far better
     * than a strip on the payroll page can.
     *
     * ORDERED BY WHAT INACTION COSTS: days that cannot be priced at all, then overtime being
     * withheld from someone, then approvals that will pay nothing, then things that merely look
     * odd. `blocking` marks the ones where running now produces a payslip somebody will dispute —
     * it does NOT stop the run, because a cutoff that cannot close is worse than one that closes
     * with a known exception on the record.
     *
     * @return list<array{key:string, label:string, count:int, blocking:bool, detail:string, where:string}>
     */
    public function exceptionsForCycle(string $start, string $end): array
    {
        $openShifts = $this->openShiftReview($start, $end);
        $needsFix = array_filter($openShifts, fn (array $r) => $r['status'] === 'needs_fix');

        $overtime = $this->autoOvertimeReview($start, $end);
        $held = array_filter($overtime, fn (array $r) => $r['kind'] === 'held');
        $flagged = array_filter($overtime, fn (array $r) => $r['kind'] === 'flagged');

        $unbacked = $this->approvedOvertimeWithoutPunch($start, $end);
        $longShifts = $this->longShiftsBetween($start, $end);

        $all = [
            [
                'key' => 'unpriced_days',
                'label' => 'Days that cannot be priced',
                'count' => count($needsFix),
                'blocking' => true,
                'detail' => 'Someone clocked in and never out on a rest day, with no schedule assigned, '
                    .'or after their own shift end. These days pay nothing until the record is corrected.',
                'where' => 'Attendance → Open shifts',
            ],
            [
                'key' => 'overtime_held',
                'label' => 'Overtime waiting on approval',
                'count' => count($held),
                'blocking' => true,
                'detail' => 'Overtime past your auto-pay cap. Running now pays the capped part only — '
                    .'the rest stays unpaid until an overtime request for that date is approved.',
                'where' => 'Overtime → Waiting on your approval',
            ],
            [
                'key' => 'approved_without_punch',
                'label' => 'Approved overtime with no punches behind it',
                'count' => count($unbacked),
                'blocking' => true,
                'detail' => 'These requests were approved but the time clock has no overtime on that '
                    .'date to match them, so they pay nothing. The employee sees "approved" and will ask why.',
                'where' => 'Attendance → correct the record, or cancel the request',
            ],
            [
                'key' => 'long_shifts',
                'label' => 'Unusually long shifts',
                'count' => count($longShifts),
                'blocking' => false,
                'detail' => 'A closed shift spanning 16 hours or more. Usually a time-out corrected to '
                    .'the wrong day — nothing flags it, because the record looks complete.',
                'where' => 'Attendance → the employee\'s day',
            ],
            [
                'key' => 'overtime_flagged',
                'label' => 'Automatic overtime worth a look',
                'count' => count($flagged),
                'blocking' => false,
                'detail' => 'Already paying in full, listed because the amount is unusual or the day was '
                    .'also late. Nothing is being withheld.',
                'where' => 'Overtime → Paid automatically',
            ],
        ];

        // Only what actually fired. A strip of five permanent zeroes is the fastest way to teach
        // somebody that this panel never means anything.
        return array_values(array_filter($all, fn (array $e) => $e['count'] > 0));
    }

    /**
     * A shift long enough to be worth a human look before payroll prices it (item 12).
     *
     * 16 HOURS IS NOT AN INVENTED FIGURE — it is the same ceiling `StoreOvertimeRequestRequest`
     * already puts on a single overtime filing. If the company will not let anyone REQUEST more
     * than 16 hours of overtime, a punch pair claiming more than 16 hours of work is by the
     * system's own reckoning implausible, and pricing it silently is the thing to avoid.
     */
    public const LONG_SHIFT_MINUTES = 16 * 60;

    /**
     * Closed shifts in the period whose punches span an implausible stretch (item 12).
     *
     * CLOSED ONLY, deliberately. An OPEN punch of 30 hours is item 6's business and is already
     * listed there; repeating it here would double-count the same day under two headings. What
     * this catches is different and quieter: a shift that WAS closed, so nothing flags it, whose
     * two punches are 18 hours apart because the time-out was corrected to the wrong day.
     *
     * @return list<array{employee_id:int, name:string|null, work_date:string, minutes:int}>
     */
    public function longShiftsBetween(string $start, string $end): array
    {
        $tz = config('app.timezone_local');
        $out = [];

        $rows = Attendance::with('employee.user')
            ->whereNotNull('time_in')
            ->whereNotNull('time_out')
            ->whereBetween('work_date', [$start, $end])
            ->orderBy('work_date')
            ->get();

        foreach ($rows as $row) {
            $employee = $row->employee;
            if (! $employee || $employee->isManagement()) {
                continue;
            }

            $in = $this->clockToMinutes($row->time_in);
            $outMin = $this->clockToMinutes($row->time_out);
            if ($in === null || $outMin === null) {
                continue;
            }
            if ($outMin < $in) {
                $outMin += 24 * 60; // clocked out after midnight
            }

            $minutes = $outMin - $in;
            if ($minutes >= self::LONG_SHIFT_MINUTES) {
                $out[] = [
                    'employee_id' => $employee->id,
                    'name' => $employee->user?->name,
                    'work_date' => Carbon::parse($row->work_date->toDateString(), $tz)->toDateString(),
                    'minutes' => $minutes,
                ];
            }
        }

        return $out;
    }

    /**
     * Approved overtime in the period that the punches cannot back — so it pays NOTHING (item 12).
     *
     * THE SILENT FAILURE THIS CATCHES. Since item 2, approved overtime pays only the overlap
     * between the approved window and the overtime actually clocked. That is correct — an approval
     * is permission, not proof — but it fails QUIETLY: the employee sees "approved" in their list,
     * HR sees "approved" in theirs, and the payslip carries nothing. Nobody is told, because from
     * every screen's point of view the request was granted.
     *
     * Legacy hours-only rows are excluded: they have no window to compare, and their capacity is
     * already bounded by the punches, so they cannot claim time nobody worked in the first place.
     *
     * @return list<array{employee_id:int, name:string|null, work_date:string, window:string}>
     */
    public function approvedOvertimeWithoutPunch(string $start, string $end): array
    {
        $requests = OvertimeRequest::with('employee.user')
            ->where('status', 'approved')
            ->whereNull('cancelled_at')
            ->whereNotNull('time_from')
            ->whereNotNull('time_to')
            ->whereBetween('work_date', [$start, $end])
            ->orderBy('work_date')
            ->get();

        $out = [];
        $clockedByEmployee = [];

        foreach ($requests as $request) {
            $employee = $request->employee;
            if (! $employee || $employee->isManagement()) {
                continue;
            }

            $clockedByEmployee[$employee->id] ??= $this->overtimeWindowsFor($employee, $start, $end);

            $date = $request->work_date->toDateString();
            $actual = $clockedByEmployee[$employee->id][$date] ?? null;

            $from = $this->hmToMinutes((string) $request->time_from);
            $to = $this->hmToMinutes((string) $request->time_to);
            if ($from === null || $to === null) {
                continue;
            }
            if ($to <= $from) {
                $to += 24 * 60;
            }

            // No clocked overtime at all on that date, or none of it overlaps what was approved.
            $overlap = $actual === null
                ? null
                : self::bestWindowOverlap($from, $to, $actual['start'], $actual['end']);

            if ($overlap === null) {
                $out[] = [
                    'employee_id' => $employee->id,
                    'name' => $employee->user?->name,
                    'work_date' => $date,
                    'window' => substr((string) $request->time_from, 0, 5).'–'.substr((string) $request->time_to, 0, 5),
                ];
            }
        }

        return $out;
    }

    /** Align a request clock window to the work-date coordinate space. */
    private static function bestWindowOverlap(int $requestStart, int $requestEnd, int $actualStart, int $actualEnd): ?array
    {
        $best = null;
        foreach ([-24 * 60, 0, 24 * 60] as $offset) {
            $start = max($actualStart, $requestStart + $offset);
            $end = min($actualEnd, $requestEnd + $offset);
            if ($end > $start && ($best === null || $end - $start > $best['end'] - $best['start'])) {
                $best = ['start' => $start, 'end' => $end];
            }
        }

        return $best;
    }

    /** @param array<int, array{start:int, end:int}> $windows */
    private static function mergeMinuteWindows(array $windows): array
    {
        usort($windows, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $merged = [];

        foreach ($windows as $window) {
            $last = array_key_last($merged);
            if ($last !== null && $window['start'] <= $merged[$last]['end']) {
                $merged[$last]['end'] = max($merged[$last]['end'], $window['end']);
            } else {
                $merged[] = $window;
            }
        }

        return $merged;
    }

    /**
     * Fill the earliest gaps in one held interval without overlapping exact approvals.
     *
     * @param  array<int, array{start:int, end:int}>  $excluded
     * @return array<int, array{start:int, end:int}>
     */
    private static function fillMinuteWindowGaps(int $start, int $end, array $excluded, int $minutes): array
    {
        if ($minutes <= 0 || $end <= $start) {
            return [];
        }

        $out = [];
        $cursor = $start;

        foreach (self::mergeMinuteWindows($excluded) as $window) {
            if ($window['end'] <= $cursor || $window['start'] >= $end) {
                continue;
            }

            $gapEnd = min($end, $window['start']);
            if ($gapEnd > $cursor) {
                $take = min($minutes, $gapEnd - $cursor);
                $out[] = ['start' => $cursor, 'end' => $cursor + $take];
                $minutes -= $take;
                if ($minutes <= 0) {
                    return $out;
                }
            }

            $cursor = max($cursor, $window['end']);
        }

        if ($minutes > 0 && $cursor < $end) {
            $out[] = ['start' => $cursor, 'end' => min($end, $cursor + $minutes)];
        }

        return $out;
    }

    /**
     * How many overtime minutes the time clock supports on each date. Both punches are required;
     * a missing clock-out can never become overtime.
     *
     * @return array<string, int> date 'Y-m-d' => max payable overtime minutes
     */
    public function overtimeCapacityFor(Employee $employee, string $start, string $end): array
    {
        return array_map(
            static fn (array $window): int => $window['end'] - $window['start'],
            $this->overtimeWindowsFor($employee, $start, $end),
        );
    }

    /** A full 8-hour day in minutes — mirrors PayrollService::REST_DAY_PREMIUM_CAP_MIN. */
    private const REST_DAY_PREMIUM_CAP_MIN = 8 * 60;

    public function workedDatesBetween(Employee $employee, string $start, string $end): array
    {
        // Slice 15 — the fifth reader of the same rows; filter in PHP off the shared memo.
        return $this->rowsBetween($employee, $start, $end)
            ->filter(fn (Attendance $r) => in_array($r->status, ['present', 'late'], true))
            ->map(fn (Attendance $r) => Carbon::parse($r->work_date)->toDateString())
            ->values()
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
    /**
     * How much of a pay period this employee was actually EMPLOYED for — Slice B10.
     *
     * Returns the share of the period's scheduled workdays that fall inside the employment window:
     * 1.0 for anybody employed throughout, less for a mid-cycle hire or leaver, 0.0 for somebody
     * the period does not touch at all.
     *
     * WHY IT IS NOT `unpaidDaysDetailFor()`. That method answers "how many days is this employee
     * not owed pay for", which folds together two different things: days they were ABSENT and days
     * they were NOT YET (or no longer) EMPLOYED. Basic pay is right to charge both. An allowance
     * must follow only the second — B10's whole point is that an allowance is deliberately not
     * prorated for absence, but a person hired on the 25th was not owed a full month of it either.
     *
     * The DIVISOR is the full period's scheduled workdays, matching the daily-rate basis: shrinking
     * it would hand a part-period employee a full period's allowance, which is the bug being fixed.
     */
    public function employmentFractionFor(Employee $employee, string $start, string $end): float
    {
        $employmentStart = $employee->hire_date?->toDateString();
        $employmentEnd = $employee->separation_date?->toDateString();

        // Nothing to prorate — the overwhelmingly common case, and worth answering without walking
        // the calendar, since payroll asks this once per employee per run.
        if ($employmentStart === null && $employmentEnd === null) {
            return 1.0;
        }

        if (($employmentStart !== null && $employmentStart <= $start)
            && ($employmentEnd === null || $employmentEnd >= $end)) {
            return 1.0;
        }

        $tz = config('app.timezone_local');
        $last = Carbon::parse($end, $tz)->startOfDay();

        $total = 0;
        $employed = 0;

        for ($d = Carbon::parse($start, $tz)->startOfDay(); $d->lte($last); $d->addDay()) {
            if (! $this->schedule->effectiveFor($employee, $d)['is_workday']) {
                continue; // a rest day is not owed to anybody, employed or not
            }

            $total++;
            $date = $d->toDateString();

            if ($employmentStart !== null && $date < $employmentStart) {
                continue;
            }
            if ($employmentEnd !== null && $date > $employmentEnd) {
                continue;
            }

            $employed++;
        }

        // A period with no scheduled workdays at all cannot be prorated by workdays; paying the
        // full amount is the employee-favourable reading and matches what basic pay does.
        return $total === 0 ? 1.0 : round($employed / $total, 6);
    }

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

        $rows = $this->rowsBetween($employee, $start, $end)
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
        // HR-declared non-working days, date => is_paid. Same shape, same one-query rule.
        $suspensions = $this->suspensionsBetween($start, $end);

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

        /**
         * The per-date ledger behind the totals — what the payslip shows when someone asks
         * "which days?".
         *
         * Every charge below records a line, so the ledger always sums to `$unpaid`. That
         * property is the whole point: a breakdown that does not reconcile with the figure it
         * explains is worse than no breakdown, because it invites the reader to trust it.
         */
        $lines = [];
        $charge = function (string $date, string $kind, float $days = 0.0, ?string $detail = null) use (&$lines) {
            $lines[] = [
                'date' => $date,
                'kind' => $kind,
                'days' => round($days, 4),
                'detail' => $detail,
            ];
        };
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
                $charge($dateStr, 'not_employed', 1.0, 'Before hire date');

                continue;
            }
            if ($employmentEnd !== null && $dateStr > $employmentEnd) {
                $unpaid += 1.0;
                $charge($dateStr, 'not_employed', 1.0, 'After last working day');

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
                        $charge($dateStr, 'holiday_forfeited', 1.0, 'Absent without pay the workday before');
                    }

                    // A holiday is not a workday for this purpose — it can neither break nor
                    // extend the "absent the day before" chain, so leave the flag as it was.
                    continue;
                }
            }

            // --- HR-declared work suspension (Slice B) -----------------------------------
            // The company cancelled the day. Whether it still costs anyone a day's pay is the
            // single decision HR recorded, and it is priced here and nowhere else.
            //
            // Ranked AFTER the holiday branch: a holiday is already covered by salary, so a
            // suspension declared over one must not be able to turn a paid statutory day into
            // an unpaid one.
            //
            // Someone who WORKED through it falls through to the ordinary paid-day path — they
            // are paid for the day they worked, with no premium: this is the company's own
            // cancellation, not a legal holiday, so no statutory rate attaches to it.
            $suspensionIsPaid = $suspensions[$dateStr] ?? null;
            if ($suspensionIsPaid !== null) {
                $suspensionRow = $rows->get($dateStr);
                // An HR override on this one person's day beats the company-wide declaration —
                // that is what an override IS, and it is how the two exceptions get expressed:
                // the skeleton crew who worked a suspended day, and the one person docked on a
                // day everybody else was paid for. Falls through to the override switch below.
                if ($suspensionRow?->status_override) {
                    // no-op: let the override decide
                } elseif (! ($suspensionRow && $suspensionRow->time_in)) {
                    if ($suspensionIsPaid === false) {
                        $unpaid += 1.0;
                        $charge($dateStr, 'suspended_unpaid', 1.0, 'Work suspended (unpaid)');
                    }

                    // `$prevWorkdayUnpaid` is deliberately left ALONE, even on an unpaid
                    // suspension. That flag exists to forfeit a following regular holiday after
                    // the employee was absent without pay — and a day the company cancelled is
                    // not the employee's absence. Charging them for the day AND taking the next
                    // holiday off them would punish them twice for someone else's decision.
                    continue;
                }
            }

            $row = $rows->get($dateStr);

            // --- HR's explicit status wins (Slice 16) -------------------------------------
            // HR asserting a status beats BOTH the clock and the leave table: it exists exactly
            // for the cases the automatic rules get wrong ("he worked, he just forgot to clock
            // in", "that was a rest day for her"). Every value is priced here — a status with no
            // rule would be a control that silently does nothing.
            if ($row && $row->status_override) {
                switch ($row->status) {
                    case 'absent':
                        $unpaid += 1.0;
                        $charge($dateStr, 'absent', 1.0, 'Marked absent by HR');
                        $prevWorkdayUnpaid = true;

                        continue 2;
                    case 'half_day':
                        $unpaid += 0.5;
                        $charge($dateStr, 'half_day', 0.5, 'Marked half day by HR');
                        $prevWorkdayUnpaid = false;

                        continue 2;
                    case 'rest_day':
                    case 'holiday':
                    case 'suspended_paid':
                        // Not a working day at all — it can neither be unpaid nor break the
                        // "absent the day before" chain.
                        continue 2;
                    case 'suspended_unpaid':
                        // HR cancelled this one person's day without pay. Costs a day, but it
                        // is still not their absence, so the following-holiday chain is left
                        // alone for the same reason as the company-wide branch above.
                        $unpaid += 1.0;
                        $charge($dateStr, 'suspended_unpaid', 1.0, 'Work suspended (unpaid)');

                        continue 2;
                    case 'on_leave':
                        // Defer to the leave that covers it; unpaid leave still costs a day.
                        $covering = $leaves->first(fn ($l) => $dateStr >= Carbon::parse($l->start_date)->toDateString()
                            && $dateStr <= Carbon::parse($l->end_date)->toDateString());
                        $isUnpaidLeave = $covering && $covering->leaveType && ! $covering->leaveType->is_paid;
                        if ($isUnpaidLeave) {
                            // FIN-034 — a HALF day of LWOP costs half a day. HR stamping the row
                            // `on_leave` must not turn it into a whole one; that would charge double
                            // for the same absence and disagree with the half-day branch below.
                            $days = ($covering && $covering->is_half_day) ? 0.5 : 1.0;
                            $unpaid += $days;
                            $charge($dateStr, 'leave_unpaid', $days, 'Leave without pay');
                        }
                        // Half a day is never a full absence, so it can't forfeit a holiday either.
                        $prevWorkdayUnpaid = $isUnpaidLeave && ! ($covering && $covering->is_half_day);

                        continue 2;
                    // 'present' / 'late' fall through to the normal worked-day path below, so
                    // late/undertime still price and the late tally still counts.
                }
            }

            // Approved leave covering this day: paid leave → no deduction; unpaid leave
            // (LWOP) → the full day is deducted (leave is full-day only).
            //
            // Slice 16 — UNLESS THEY ACTUALLY CAME IN. A clock-in is proof the leave wasn't
            // taken, so the day is worked and paid. Before this the leave branch ran first and
            // always `continue`d, so someone on unpaid leave who turned up was docked a full day
            // despite working it, and HR marking them present changed nothing.
            $leave = $leaves->first(fn ($l) => $dateStr >= Carbon::parse($l->start_date)->toDateString()
                && $dateStr <= Carbon::parse($l->end_date)->toDateString());

            // FIN-034 — HALF-DAY LEAVE, which cannot go through either branch below.
            //
            // The full-day branch is guarded on `! ($row && $row->time_in)`, because a clock-in is
            // proof the leave wasn't taken. On a half day that reasoning inverts: the employee is
            // SUPPOSED to clock in and work the other half, so reaching that guard would silently
            // void the leave and dock nothing — while the balance had already been spent.
            //
            // Half of PAID leave costs nothing (the credit pays it); half of LWOP costs 0.5 of a
            // day. Either way the remaining half is expected worked, so lateness and undertime are
            // still priced against it — the same treatment an approved half-day request gets, which
            // is the point: the two are now the same shape with different funding.
            if ($leave && $leave->is_half_day) {
                $halfUnpaid = ($leave->leaveType && ! $leave->leaveType->is_paid) ? 0.5 : 0.0;
                $unpaid += $halfUnpaid;
                if ($halfUnpaid > 0) {
                    $charge($dateStr, 'leave_unpaid', $halfUnpaid, 'Half day leave without pay');
                }

                if ($row && $row->status === 'late') {
                    $lateCount++;
                }

                $eoFrac = 0.0;
                foreach (($earlyOuts[$dateStr] ?? []) as $eo) {
                    $eoFrac += $this->earlyOutFraction(
                        (string) $eo->early_out_time,
                        $eff,
                        (string) $leave->half_day_period,
                    );
                }
                $eoFrac = min(max(0.0, 1.0 - $halfUnpaid), $eoFrac);
                $unpaid += $eoFrac;
                if ($eoFrac > 0) {
                    $charge($dateStr, 'early_out', $eoFrac, 'Approved early-out');
                }

                if ($deductionsActive && $row && $row->time_in) {
                    $ded = $this->deduction->forDay(
                        $this->deductionCtx(
                            $row,
                            $eff,
                            $policy,
                            (string) $leave->half_day_period,
                            1.0 - $halfUnpaid - $eoFrac,
                            isset($earlyOuts[$dateStr]),
                        ),
                        $policy,
                    );
                    $unpaid += $ded->unpaidFraction;
                    $lateMinutes += $ded->lateMinutes;
                    $undertimeMinutes += $ded->undertimeMinutes;
                    $lateDays += $ded->lateFraction;
                    $undertimeDays += $ded->undertimeFraction;
                    $flags = array_merge($flags, $ded->flags);
                    // Recorded per DAY, unlike the running totals above.
                    //
                    // These sit inside the deduction block deliberately, which means no `late`
                    // line exists when no late-deduction mode is on. That is the correct scope,
                    // not a gap: `late_minutes` on the payslip comes from this same block, so a
                    // policy that charges nothing prints no minutes either. The note explains the
                    // figure beside it, and the two can never disagree about whether it exists.
                    if ($ded->lateMinutes > 0) {
                        $charge($dateStr, 'late', $ded->lateFraction, $ded->lateMinutes . ' min late');
                    }
                    if ($ded->undertimeMinutes > 0) {
                        $charge($dateStr, 'undertime', $ded->undertimeFraction, $ded->undertimeMinutes . ' min undertime');
                    }
                }

                // Half a day worked is not an absence, so a following regular holiday stands.
                // (Nor is half a day of PAID leave — only full LWOP forfeits it.)
                $prevWorkdayUnpaid = false;

                continue;
            }

            if ($leave && ! ($row && $row->time_in)) {
                $isUnpaidLeave = $leave->leaveType && ! $leave->leaveType->is_paid;
                if ($isUnpaidLeave) {
                    $unpaid += 1.0;
                    $charge($dateStr, 'leave_unpaid', 1.0, 'Leave without pay');
                }
                // LWOP the day before a regular holiday forfeits the holiday pay; paid leave
                // does not.
                $prevWorkdayUnpaid = $isUnpaidLeave;

                continue;
            }

            // Approved half-day request(s): 0.5 each. With two halves the whole day is off.
            // With one half off, the employee is expected to work the OTHER half — so a
            // per-minute mode still prices late/undertime against that half (F-3).
            if ($dayHalves = ($halfDays[$dateStr] ?? null)) {
                // Item 5 — clamp at one full day. The API blocks a duplicate date+period while
                // pending/approved, so AM+PM = 1.0 is the practical maximum, but that check is a
                // form-request `exists()` with no unique index behind it: two concurrent submits
                // can both pass. Cheap defence against that and against direct inserts.
                $halfUnpaid = min(1.0, 0.5 * $dayHalves->count());
                $unpaid += $halfUnpaid;
                $charge($dateStr, 'half_day', $halfUnpaid, 'Approved half-day request');

                // Item 8 — a late arrival on a half-day used to escape the per-N-lates penalty
                // entirely, because this branch never touched the tally.
                if ($row && $row->status === 'late') {
                    $lateCount++;
                }

                // Item 8 — and an approved early-out on a half-day cost NOTHING: this branch
                // never added earlyOutFraction, while `governed_by_early_out` told
                // DeductionService not to price the undertime either. Charge it here, capped by
                // whatever of the day is still payable.
                //
                // Two halves off is the whole day, which earlyOutFraction() prices at zero:
                // nothing is left for an early departure to shorten.
                $offHalf = $dayHalves->count() > 1
                    ? HalfDayWindowService::BOTH
                    : (string) $dayHalves->first()->period;

                $eoFrac = 0.0;
                foreach (($earlyOuts[$dateStr] ?? []) as $eo) {
                    $eoFrac += $this->earlyOutFraction((string) $eo->early_out_time, $eff, $offHalf);
                }
                $eoFrac = min(max(0.0, 1.0 - $halfUnpaid), $eoFrac);
                $unpaid += $eoFrac;
                if ($eoFrac > 0) {
                    $charge($dateStr, 'early_out', $eoFrac, 'Approved early-out');
                }

                if ($deductionsActive && $dayHalves->count() === 1 && $row && $row->time_in) {
                    $ded = $this->deduction->forDay(
                        $this->deductionCtx($row, $eff, $policy, (string) $dayHalves->first()->period, 1.0 - $halfUnpaid - $eoFrac, isset($earlyOuts[$dateStr])),
                        $policy,
                    );
                    $unpaid += $ded->unpaidFraction;
                    $lateMinutes += $ded->lateMinutes;
                    $undertimeMinutes += $ded->undertimeMinutes;
                    $lateDays += $ded->lateFraction;
                    $undertimeDays += $ded->undertimeFraction;
                    $flags = array_merge($flags, $ded->flags);
                    // Recorded per DAY, unlike the running totals above.
                    //
                    // These sit inside the deduction block deliberately, which means no `late`
                    // line exists when no late-deduction mode is on. That is the correct scope,
                    // not a gap: `late_minutes` on the payslip comes from this same block, so a
                    // policy that charges nothing prints no minutes either. The note explains the
                    // figure beside it, and the two can never disagree about whether it exists.
                    if ($ded->lateMinutes > 0) {
                        $charge($dateStr, 'late', $ded->lateFraction, $ded->lateMinutes . ' min late');
                    }
                    if ($ded->undertimeMinutes > 0) {
                        $charge($dateStr, 'undertime', $ded->undertimeFraction, $ded->undertimeMinutes . ' min undertime');
                    }
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
                    // Recorded per DAY, unlike the running totals above.
                    //
                    // These sit inside the deduction block deliberately, which means no `late`
                    // line exists when no late-deduction mode is on. That is the correct scope,
                    // not a gap: `late_minutes` on the payslip comes from this same block, so a
                    // policy that charges nothing prints no minutes either. The note explains the
                    // figure beside it, and the two can never disagree about whether it exists.
                    if ($ded->lateMinutes > 0) {
                        $charge($dateStr, 'late', $ded->lateFraction, $ded->lateMinutes . ' min late');
                    }
                    if ($ded->undertimeMinutes > 0) {
                        $charge($dateStr, 'undertime', $ded->undertimeFraction, $ded->undertimeMinutes . ' min undertime');
                    }
                }

                $prevWorkdayUnpaid = false; // they showed up

                continue; // present or late → the day itself is paid
            }
            if ($row && $row->status === 'absent') {
                $unpaid += 1.0;
                $charge($dateStr, 'absent', 1.0, 'Absent');
                $prevWorkdayUnpaid = true;

                continue;
            }
            // No usable record: unpaid only once the workday is in the past.
            if ($d->lt($today)) {
                $unpaid += 1.0;
                $charge($dateStr, 'absent', 1.0, 'No time in recorded');
                $prevWorkdayUnpaid = true;
            } else {
                $prevWorkdayUnpaid = false; // today/future — not an absence yet
            }
        }

        // Tardiness penalty: every N lates in the range costs one day's pay. Now an
        // INDEPENDENT switch, so it can stack on top of the per-time (`per_hour`) charge.
        $latePenaltyDays = 0.0;
        if ($policy->late_penalty_enabled && $policy->lates_per_deduction > 0) {
            // Item 13b.13 — reported separately so the payslip can name it, instead of the
            // days disappearing into `unpaid_days` alongside genuine absences.
            $latePenaltyDays = (float) intdiv($lateCount, (int) $policy->lates_per_deduction);
            $unpaid += $latePenaltyDays;

            if ($latePenaltyDays > 0) {
                // Dated to the period end, not to a day: it is charged for a COUNT of lates
                // across the range, so pinning it on one of them would misattribute it.
                $charge($last->toDateString(), 'late_penalty', $latePenaltyDays,
                    "{$lateCount} lates × 1 day per {$policy->lates_per_deduction}");
            }
        }

        return new UnpaidDaysResult(
            unpaidDays: $unpaid,
            lateMinutes: $lateMinutes,
            undertimeMinutes: $undertimeMinutes,
            lateDays: round($lateDays, 6),
            undertimeDays: round($undertimeDays, 6),
            latePenaltyDays: $latePenaltyDays,
            flags: array_values(array_unique($flags)),
            lines: $lines,
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

    /** Minute-of-day → 'HH:MM'. Values past 1440 wrap, so an overnight end reads as its own clock. */
    private function minutesToHm(int $minutes): string
    {
        $minutes %= 24 * 60;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
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
     * THE SHORTFALL IS MEASURED AGAINST THE HALF ACTUALLY WORKED. Leaving at 10:00 on a day whose
     * afternoon is already approved off is two hours short of a noon finish, not seven hours short
     * of a five o'clock one — and the old reading charged the seven: half a day on top of the half
     * already unpaid, so a two-hour early departure cost the WHOLE day. The `1.0 − halfUnpaid` cap
     * at the call sites hid how big the error was by clamping a wrong number instead of computing
     * a right one.
     *
     * THE DENOMINATOR IS DELIBERATELY UNCHANGED — still the full scheduled span, the same basis
     * every ordinary early-out is priced on. Pricing the shortfall against the HALF instead (so
     * half a morning costs 0.25 of a day rather than 0.222) is a different pay model, not a bug
     * fix, and that is HR's call rather than this method's.
     *
     * @param  array<string, mixed>  $eff
     * @param  ?string  $offHalf  a HalfDayWindowService::AM|PM|BOTH value, or null for a full day
     */
    private function earlyOutFraction(string $time, array $eff, ?string $offHalf = null): float
    {
        $toMinutes = static function (?string $hm): ?int {
            if (! $hm) {
                return null;
            }
            [$h, $m] = array_map('intval', explode(':', substr($hm, 0, 5)));

            return $h * 60 + $m;
        };

        // The span the day is PRICED over stays the full shift; the moment the employee was due
        // to leave comes from the half they were actually expected to work.
        $start = $toMinutes($eff['start_time'] ?? null);
        $end = $toMinutes($eff['end_time'] ?? null);
        $chosen = $toMinutes($time);

        if ($start === null || $end === null || $chosen === null || $end <= $start) {
            return 0.5;
        }

        // No window to leave early FROM — the whole day is off, or the worked half has no times
        // configured. Zeroed here rather than left to the caller's `1.0 − halfUnpaid` cap, so the
        // value is right on its own terms instead of only after somebody clamps it, and so an
        // unconfigured half cannot be billed against the full shift's 5 o'clock end.
        [$workedStartTime, $workedEndTime] = $this->halfDays->windowFor($eff, $offHalf);
        if ($workedStartTime === null) {
            return 0.0;
        }

        $workedEnd = $toMinutes($workedEndTime) ?? $end;

        $span = $end - $start;
        $undertime = max(0, min($span, $workedEnd - $chosen));

        return $undertime / $span;
    }

    /**
     * The start of the window the employee was ACTUALLY expected to work on $localDay — the
     * worked half's start on an approved half day, the schedule's start otherwise.
     *
     * NULL MEANS THERE IS NO START TO BE LATE FOR — both halves off, or a half day whose worked
     * half has no window configured. Every caller has to treat that as "not late" rather than
     * falling back to the schedule: measuring someone against a shift nobody defined for them is
     * the bug this replaces, and the full-day start is the most wrong answer available.
     *
     * @param  array<string, mixed>  $eff  the employee's effective schedule for $localDay
     * @param  ?string  $offHalf  a HalfDayWindowService::AM|PM|BOTH value, or null for a full day
     */
    private function expectedStart(Carbon $localDay, array $eff, ?string $offHalf): ?Carbon
    {
        [$start] = $this->halfDays->windowFor($eff, $offHalf);

        if ($start === null) {
            // No window to judge against. On a HALF DAY that is an answer, not a gap — the whole
            // day is off, or the worked half has no times configured — and guessing one is the
            // bug this replaces. On an ordinary day a missing start_time still falls back to
            // FALLBACK_START exactly as it always did.
            if ($offHalf !== null) {
                return null;
            }

            $start = self::FALLBACK_START;
        }

        [$h, $m] = explode(':', $start);

        return $localDay->copy()->setTime((int) $h, (int) $m, 0);
    }

    /**
     * Classify an arrival against the schedule: early (before start), on_time
     * (within grace), or late (past grace) with minutes past start.
     *
     * Half-day aware, for the same reason arrivalStatus() is: the badge on the roster and the
     * status stored on the row are two renderings of one fact, and they must not disagree.
     *
     * @param  array<string, mixed>  $eff
     * @param  ?string  $offHalf  a HalfDayWindowService::AM|PM|BOTH value, or null for a full day
     * @return array{category:string, late_minutes:int}
     */
    private function arrivalCategory(Carbon $timeInLocal, array $eff, Carbon $dateCarbon, ?string $offHalf = null): array
    {
        $start = $this->expectedStart($dateCarbon, $eff, $offHalf);
        if ($start === null) {
            return ['category' => 'on_time', 'late_minutes' => 0];
        }

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
