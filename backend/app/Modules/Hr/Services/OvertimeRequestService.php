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
 * APPROVED one at the configured OT rate (see PayrollService, OT-3). Mirrors EarlyOutRequestService,
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

    /**
     * How a date's overtime is classified for pay, resolved from the employee's schedule + the
     * holiday calendar + the HR-editable OT rates. The filing form shows this live so the employee
     * sees the rate BEFORE submitting; payroll re-derives it independently at run time (OT-3), so
     * this is display only and can never be trusted into pay.
     *
     * Also returns the day's scheduled END (null on a rest day) — overtime can only start after the
     * shift is over, so the form uses it as a floor for "time from" and the guard below enforces it.
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
            'schedule_end' => $scheduleEnd,
        ];
    }

    /**
     * Overtime is time worked BEYOND the shift, so on a scheduled workday it cannot start before
     * that day's end time. A rest day / non-workday has no shift to be beyond, so it's unrestricted.
     */
    private function assertAfterShift(Employee $employee, string $workDate, string $timeFrom): void
    {
        $end = $this->classify($employee, $workDate)['schedule_end'];
        if ($end === null) {
            return;
        }

        $toMinutes = static function (string $hm): int {
            [$h, $m] = array_map('intval', explode(':', substr($hm, 0, 5)));

            return $h * 60 + $m;
        };

        if ($toMinutes($timeFrom) < $toMinutes($end)) {
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
        $query = OvertimeRequest::with(['employee.user', 'reviewer'])
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
        // that becomes pay. (A pre-OT-4 caller may still pass `hours` directly.)
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

    public function approve(OvertimeRequest $request, User $reviewer): OvertimeRequest
    {
        $this->assertPending($request, 'approve');

        $request->update([
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $request->load(['employee.user', 'reviewer']);
        $employeeName = $request->employee->user?->name ?? $request->employee->name;
        $this->audit->log('approved', 'Overtime', "Approved {$employeeName}'s overtime request", $request);

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

        $request->load(['employee.user', 'reviewer']);
        $employeeName = $request->employee->user?->name ?? $request->employee->name;
        $this->audit->log('declined', 'Overtime', "Declined {$employeeName}'s overtime request", $request);

        return $request;
    }

    /**
     * Cancel an overtime request (Slice 6). HR only — an approval is a commitment of company
     * money, so undoing one is an HR act, and it keeps a single clear audit trail. Before
     * this, `approve()`/`reject()` both guarded on `pending`, which left an approved request
     * frozen: if the work never happened the overtime was paid anyway.
     *
     * Payroll ignores a cancelled request from the next recompute onward. An already-DISBURSED
     * payslip is deliberately left alone (Slice 5 protects it) — HR settles the difference
     * manually rather than have pay silently move under a payslip someone has already been given.
     */
    public function cancel(OvertimeRequest $request, User $actor, string $reason): OvertimeRequest
    {
        if (! $actor->hasAnyRole(['hr_officer', 'super_admin'])) {
            throw new AuthorizationException('Only HR can cancel an overtime request.');
        }

        if (! in_array($request->status, ['pending', 'approved'], true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot cancel a request that is {$request->status}."],
            ]);
        }

        $wasApproved = $request->status === 'approved';

        $request->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'cancel_reason' => $reason,
        ]);

        $request->load(['employee.user', 'reviewer', 'canceller']);
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

        $request->load(['employee.user', 'reviewer', 'canceller']);
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
