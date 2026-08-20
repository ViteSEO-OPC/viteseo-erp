<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OvertimeRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Overtime requests — an employee asks for N overtime HOURS on a date. HR approves; payroll pays each
 * APPROVED one at the configured OT rate (see PayrollService). Mirrors EarlyOutRequestService,
 * opposite sign (positive pay, not a deduction). No shift-window check — OT can fall on a workday OR
 * a rest day; the hours bound lives in StoreOvertimeRequestRequest.
 */
class OvertimeRequestService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ScheduleService $schedule,
        private readonly HolidayService $holidays,
        private readonly AttendancePolicyService $policy,
        private readonly PayrollFreezeService $freeze,
        private readonly RetroFlagService $retro,
    ) {}

    /**
     * Hours in a from→to window, to 2dp. A window whose end is at/before its start is treated as
     * crossing MIDNIGHT (e.g. 22:00→02:00 = 4h) — normal for overtime. Pure/static, no DB.
     */
    public static function hoursBetween(string $from, string $to): float
    {
        $toMinutes = static function (string $hm): int {
            [$h, $m] = array_map('intval', explode(':', substr($hm, 0, 5)));

            return $h * 60 + $m;
        };

        $start = $toMinutes($from);
        $end = $toMinutes($to);
        if ($end <= $start) {
            $end += 24 * 60; // crosses midnight into the next day
        }

        return round(($end - $start) / 60, 2);
    }

    /** Do two overtime windows on the same work date collide? (Item 10.) */
    public static function windowsOverlap(string $aFrom, string $aTo, string $bFrom, string $bTo): bool
    {
        $toMinutes = static function (string $hm): int {
            [$h, $m] = array_map('intval', explode(':', substr($hm, 0, 5)));

            return $h * 60 + $m;
        };

        $span = static function (string $from, string $to) use ($toMinutes): array {
            $start = $toMinutes($from);
            $end = $toMinutes($to);
            if ($end <= $start) {
                $end += 24 * 60;
            }

            return [$start, $end];
        };

        [$aStart, $aEnd] = $span($aFrom, $aTo);
        [$bStart, $bEnd] = $span($bFrom, $bTo);

        foreach ([-24 * 60, 0, 24 * 60] as $offset) {
            if (min($aEnd, $bEnd + $offset) > max($aStart, $bStart + $offset)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How a date's overtime is classified for pay, resolved from the employee's schedule + the
     * holiday calendar + the HR-editable OT rates. The filing form shows this live so the employee
     * sees the rate BEFORE submitting; payroll re-derives it independently at run time, so
     * this is display only and can never be trusted into pay.
     *
     * @return array{code:string, label:string, rate_pct:int, is_workday:bool, schedule_end:?string}
     */
    public function classify(Employee $employee, string $date): array
    {
        $tz = config('app.timezone_local');
        $day = Carbon::parse($date, $tz);

        $holidayType = $this->holidays->typedHolidaysBetween($day, $day)[$day->toDateString()] ?? null;
        $eff = $this->schedule->effectiveFor($employee, $day);
        $isWorkday = (bool) ($eff['is_workday'] ?? true);
        $ratePct = PayrollService::overtimeMultiplierPct($holidayType, $isWorkday, $this->policy->get());
        $scheduleEnd = $isWorkday && ! empty($eff['end_time']) ? substr((string) $eff['end_time'], 0, 5) : null;
        // Item 11 — the START is needed to tell an overnight shift from a day one. Without it
        // "before the end" is meaningless on a 22:00–06:00 schedule, where the end is a smaller
        // number than the start.
        $scheduleStart = $isWorkday && ! empty($eff['start_time']) ? substr((string) $eff['start_time'], 0, 5) : null;

        $code = match (true) {
            $holidayType === 'regular' => 'regular_holiday',
            $holidayType === 'special_non_working' => 'special_day',
            ! $isWorkday => 'rest_day',
            default => 'regular_day',
        };

        return [
            'code' => $code,
            'label' => match ($code) {
                'regular_holiday' => 'Regular holiday',
                'special_day' => 'Special day',
                'rest_day' => 'Rest day',
                default => 'Regular day',
            },
            'rate_pct' => $ratePct,
            'is_workday' => $isWorkday,
            'schedule_start' => $scheduleStart,
            'schedule_end' => $scheduleEnd,
        ];
    }

    /**
     * Overtime is time worked BEYOND the shift, so on a scheduled workday it cannot start before
     * that day's end time. A rest day / non-workday has no shift to be beyond, so it's unrestricted.
     */
    private function assertAfterShift(Employee $employee, string $workDate, string $timeFrom): void
    {
        $shift = $this->classify($employee, $workDate);
        $start = $shift['schedule_start'];
        $end = $shift['schedule_end'];
        if ($start === null || $end === null) {
            return;
        }

        $toMinutes = static function (string $hm): int {
            [$h, $m] = array_map('intval', explode(':', substr($hm, 0, 5)));

            return $h * 60 + $m;
        };

        $startMin = $toMinutes($start);
        $endMin = $toMinutes($end);
        if ($endMin <= $startMin) {
            $endMin += 24 * 60; // the shift ends on the following day
        }

        $raw = $toMinutes($timeFrom);
        $nearest = $raw;
        foreach ([-24 * 60, 0, 24 * 60] as $offset) {
            if (abs(($raw + $offset) - $endMin) < abs($nearest - $endMin)) {
                $nearest = $raw + $offset;
            }
        }

        if ($nearest < $endMin) {
            throw ValidationException::withMessages([
                'time_from' => ["Overtime starts after your shift ends at {$end}."],
            ]);
        }
    }

    /**
     * What the company's overtime setting means for FILING. Requests only make sense in
     * `approved_only`: `none` means overtime is off, and `auto_after_threshold` records it straight
     * from the time clock, so asking for it is meaningless. Employee-readable (the policy itself is
     * HR-only), so the request form can explain instead of offering a form that would be rejected.
     *
     * @return array{mode:string, requests_enabled:bool, note:string}
     */
    public function settings(): array
    {
        $mode = (string) $this->policy->get()->overtime_mode;

        return [
            'mode' => $mode,
            'requests_enabled' => $mode === 'approved_only',
            'note' => match ($mode) {
                'approved_only' => 'Overtime must be approved before it is paid.',
                'none' => 'Overtime is currently switched off for the company. Talk to HR if you need to work beyond your schedule.',
                default => 'Overtime is recorded automatically from your time clock — no request needed. Clock out after your shift ends and any overtime past the company minimum is paid.',
            },
        ];
    }

    /** HR sees all; an employee sees only their own. */
    public function list(User $user): Collection
    {
        $query = OvertimeRequest::with(['employee.user', 'reviewer', 'canceller', 'filer'])
            ->orderByDesc('work_date')
            ->latest();

        if (! $user->hasAnyRole(['hr_officer', 'super_admin'])) {
            $query->where('employee_id', $user->employee?->id);
        }

        return $query->get();
    }

    /** @param array<string, mixed> $data */
    public function create(Employee $employee, array $data): OvertimeRequest
    {
        // Filing is only meaningful in `approved_only` — mirrors the note the form shows, so the
        // rule holds even if the UI is bypassed.
        $settings = $this->settings();
        if (! $settings['requests_enabled']) {
            throw ValidationException::withMessages(['work_date' => [$settings['note']]]);
        }

        if (isset($data['time_from'])) {
            $this->assertAfterShift($employee, $data['work_date'], $data['time_from']);
        }

        // Hours are DERIVED from the window server-side — the client never supplies the number
        // that becomes pay. (A pre- caller may still pass `hours` directly.)
        $hours = isset($data['time_from'], $data['time_to'])
            ? self::hoursBetween($data['time_from'], $data['time_to'])
            : (float) ($data['hours'] ?? 0);

        $req = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'work_date' => $data['work_date'],
            'time_from' => $data['time_from'] ?? null,
            'time_to' => $data['time_to'] ?? null,
            'hours' => $hours,
            'reason' => $data['reason'],
            'status' => 'pending',
        ])->load('employee.user');

        $hours = rtrim(rtrim((string) $req->hours, '0'), '.');
        $this->audit->log(
            'submitted',
            'Overtime',
            'Overtime request (' . $req->work_date->format('M j') . ' · ' . $hours . 'h)',
            $req,
        );

        return $req;
    }

    /**
     * HR files overtime for an employee on a date that has already passed (item 10).
     *
     * @param  array<string, mixed>  $data
     */
    public function createFor(Employee $employee, array $data, User $filedBy): OvertimeRequest
    {
        // Same mode gate as ordinary filing: in `auto_after_threshold` the clock already records
        // overtime and in `none` there is none to record, so a request would authorise nothing.
        $settings = $this->settings();
        if (! $settings['requests_enabled']) {
            throw ValidationException::withMessages(['work_date' => [$settings['note']]]);
        }

        $this->assertAfterShift($employee, $data['work_date'], $data['time_from']);

        $req = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'filed_by' => $filedBy->id,
            'work_date' => $data['work_date'],
            'time_from' => $data['time_from'],
            'time_to' => $data['time_to'],
            // Derived server-side, exactly as on the employee path — the client never supplies
            // the number that becomes pay.
            'hours' => self::hoursBetween($data['time_from'], $data['time_to']),
            'reason' => $data['reason'],
            'status' => 'pending',
        ])->load(['employee.user', 'filer']);

        $hours = rtrim(rtrim((string) $req->hours, '0'), '.');
        $employeeName = $req->employee->user?->name ?? $req->employee->name;
        $this->audit->log(
            'submitted',
            'Overtime',
            "Filed {$employeeName}'s overtime for ".$req->work_date->format('M j')." ({$hours}h) after the fact",
            $req,
        );

        return $req;
    }

    public function approve(OvertimeRequest $request, User $reviewer): OvertimeRequest
    {
        $this->assertPending($request, 'approve');

        $request->update([
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $request->load(['employee.user', 'reviewer', 'filer']);
        $employeeName = $request->employee->user?->name ?? $request->employee->name;
        $this->audit->log('approved', 'Overtime', "Approved {$employeeName}'s overtime request", $request);

        // Approving overtime for a cutoff that has already been paid changes what that
        // period SHOULD have paid. The approval stands (the overtime was worked), and the
        // difference is recorded so an adjustment run can settle it instead of being remembered.
        $this->retro->flagIfSettled(
            $request,
            $request->employee_id,
            $request->work_date instanceof \DateTimeInterface
                ? $request->work_date->format('Y-m-d')
                : (string) $request->work_date,
            null,
            \App\Modules\Hr\Models\RetroFlag::OWED,
            'Overtime approved after the period was paid',
        );

        return $request;
    }

    public function reject(OvertimeRequest $request, User $reviewer, string $reason): OvertimeRequest
    {
        $this->assertPending($request, 'reject');

        $request->update([
            'status' => 'rejected',
            'review_note' => $reason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $request->load(['employee.user', 'reviewer', 'filer']);
        $employeeName = $request->employee->user?->name ?? $request->employee->name;
        $this->audit->log('declined', 'Overtime', "Declined {$employeeName}'s overtime request", $request);

        return $request;
    }

    /** Cancel an overtime request. */
    public function cancel(OvertimeRequest $request, User $actor, string $reason): OvertimeRequest
    {
        $isHr = $actor->hasAnyRole(['hr_officer', 'super_admin']);
        $isOwner = $actor->employee !== null
            && (int) $actor->employee->id === (int) $request->employee_id;

        if (! $isHr && ! $isOwner) {
            throw new AuthorizationException('You cannot cancel this overtime request.');
        }

        if (! in_array($request->status, ['pending', 'approved'], true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot cancel a request that is {$request->status}."],
            ]);
        }

        // The employee's reach stops at `pending`. Once it is approved the money is committed and
        // withdrawing it is HR's call, for the reason in the docblock.
        if (! $isHr && $request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['You can only withdraw a request while it is pending. Ask HR to cancel an approved one.'],
            ]);
        }

        $wasApproved = $request->status === 'approved';

        $request->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'cancel_reason' => $reason,
        ]);

        // Cancelling an APPROVED request whose period has already been paid means the
        // company paid overtime its own records now say was never authorised. The docblock above
        // says HR settles that manually; this is what makes "manually" a tracked queue instead of
        // someone's memory. Recovering it is a wage deduction needing consent (Art. 113), so it is
        // surfaced for a person rather than netted off a future payslip.
        if ($wasApproved) {
            $this->retro->flagIfSettled(
                $request,
                $request->employee_id,
                $request->work_date->format('Y-m-d'),
                null,
                \App\Modules\Hr\Models\RetroFlag::OVERPAID,
                'Approved overtime cancelled after it was paid',
            );
        }

        $request->load(['employee.user', 'reviewer', 'canceller', 'filer']);
        $name = $request->employee->user?->name ?? $request->employee->name;
        $this->audit->log(
            'cancelled',
            'Overtime',
            "Cancelled {$name}'s " . ($wasApproved ? 'approved ' : '')
                . 'overtime request for ' . $request->work_date->format('M j') . " — {$reason}",
            $request,
        );

        return $request;
    }

    /**
     * Undo the decision — back to pending, so HR can rule on it again. Unlike cancel()
     * (a final "this OT is off"), a reopened request is still live. Reopening an approved
     * one takes the OT pay back out of the next recompute, so it is refused once payroll
     * has settled that date; a rejected or cancelled one has nothing to take back.
     */
    public function reopen(OvertimeRequest $request, ?string $reason = null): OvertimeRequest
    {
        if ($request->status === 'pending') {
            throw ValidationException::withMessages([
                'status' => ['This request is still pending — there is nothing to undo.'],
            ]);
        }

        if ($request->status === 'approved') {
            $this->freeze->assertOpen(
                $request->employee_id,
                $request->work_date->toDateString(),
                null,
                'reopen this overtime request',
            );
        }

        $request->update([
            'status' => 'pending',
            'review_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancel_reason' => null,
        ]);

        $request->load(['employee.user', 'reviewer', 'canceller', 'filer']);
        $name = $request->employee->user?->name ?? $request->employee->name;
        $this->audit->log(
            'reopened',
            'Overtime',
            "Reopened {$name}'s overtime request for " . $request->work_date->format('M j')
                . ($reason ? " — {$reason}" : ''),
            $request,
        );

        return $request;
    }

    private function assertPending(OvertimeRequest $request, string $action): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["Cannot {$action} a request that is {$request->status}."],
            ]);
        }
    }
}
