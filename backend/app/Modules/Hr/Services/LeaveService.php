<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveSetting;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\LeaveTypeAllotment;
use App\Modules\Hr\Models\SharedLeaveBalance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    public function __construct(
        private readonly HolidayService $holidays,
        private readonly AuditService $audit,
        private readonly ViolationService $violations,
        private readonly PayrollFreezeService $freeze,
        private readonly RetroFlagService $retro,
        private readonly AttendanceService $attendance,
        private readonly HalfDayWindowService $windows,
    ) {}

    /**
     * Leave days = weekdays (Mon–Fri) between start and end, inclusive, MINUS any
     * non-working holidays in range ( — §5.5c). Half-day = 0.5, single day.
     * Weekends and holidays don't consume balance.
     */
    public function computeDays(string $start, string $end, bool $isHalfDay): float
    {
        $startDate = Carbon::parse($start);
        $endDate = Carbon::parse($end);

        if ($isHalfDay) {
            if (! $startDate->isSameDay($endDate)) {
                throw ValidationException::withMessages([
                    'is_half_day' => ['A half-day leave must start and end on the same day.'],
                ]);
            }

            return 0.5;
        }

        $holidays = $this->holidays->nonWorkingDatesBetween($startDate, $endDate);

        $days = 0;
        for ($d = $startDate->copy(); $d->lte($endDate); $d->addDay()) {
            if (! $d->isWeekend() && ! in_array($d->toDateString(), $holidays, true)) {
                $days++;
            }
        }

        return (float) $days;
    }

    /**
     * Reject a past-dated request unless the leave type explicitly allows backdating,
     * and then only within the type's window (backdate_limit_days; NULL = no limit).
     * "Past" and the window are measured in whole days against today in Asia/Manila
     * (day-boundary rule, §5.5b), so timezone can't let a request slip through.
     */
    private function assertBackdatingAllowed(?LeaveType $type, string $startDate): void
    {
        $tz = config('app.timezone_local');
        $today = Carbon::now($tz)->startOfDay();
        $start = Carbon::parse($startDate, $tz)->startOfDay();

        if ($start->gte($today)) {
            return; // today or future — always allowed
        }

        if (! $type || ! $type->allow_backdated) {
            throw ValidationException::withMessages([
                'start_date' => ['This leave type cannot be filed for past dates.'],
            ]);
        }

        if ($type->backdate_limit_days !== null) {
            $earliest = $today->copy()->subDays($type->backdate_limit_days);
            if ($start->lt($earliest)) {
                throw ValidationException::withMessages([
                    'start_date' => ["This leave type can only be backdated up to {$type->backdate_limit_days} day(s) — no earlier than {$earliest->toDateString()}."],
                ]);
            }
        }
    }

    /** True when the company runs one shared leave pool per employee (vs per-type). */
    private function isSharedMode(): bool
    {
        return LeaveSetting::current()->allotment_mode === LeaveSetting::MODE_SHARED;
    }

    /**
     * The leave types this employee may actually file, newest rule first (§ Leave Type config).
     *
     * @return Collection<int, LeaveType>
     */
    public function selectableTypesFor(Employee $employee): Collection
    {
        $types = LeaveType::orderBy('name')->get();

        if ($this->isSharedMode()) {
            return $types;
        }

        $year = now(config('app.timezone_local'))->year;

        $entitledByType = LeaveBalance::where('employee_id', $employee->id)
            ->where('year', $year)
            ->pluck('entitled_days', 'leave_type_id');

        $defaultsByType = LeaveTypeAllotment::where('employment_status', $employee->employment_status)
            ->pluck('days_per_year', 'leave_type_id');

        return $types->filter(function (LeaveType $type) use ($entitledByType, $defaultsByType) {
            // An unlimited type has no entitlement to be zero, so it is always offered.
            if ($type->is_unlimited) {
                return true;
            }

            // A balance row is the employee's actual entitlement, so it decides even when it is
            // zero — that is HR having said "not this person", not a missing configuration.
            $entitled = $entitledByType->has($type->id)
                ? (float) $entitledByType->get($type->id)
                : (float) ($defaultsByType->get($type->id) ?? 0);

            return $entitled > 0;
        })->values();
    }

    /** The employee's balances for the current (Manila) year, with the type. */
    public function balancesForCurrentYear(Employee $employee): Collection
    {
        $year = now(config('app.timezone_local'))->year;

        // `whereHas` drops balances whose leave type has been deleted. Deleting a type only soft-
        // deletes it, so its balance rows survive with a `leaveType` that now resolves to NULL —
        // and `LeaveBalanceResource` dereferences that relation. One deleted type therefore took
        // the whole endpoint down with a 500 for every employee who had ever held that leave,
        // which is what emptied the Work Schedule cards in both allotment modes.
        return $employee->leaveBalances()
            ->with('leaveType')
            ->whereHas('leaveType')
            ->where('year', $year)
            ->get();
    }

    /**
     * The employee's leave summary for the current (Manila) year: the active
     * allotment mode, their per-type balances (for not-shared display) and, in
     * shared mode, the single pool balance. The frontend renders whichever the
     * mode calls for.
     *
     * @return array{mode: string, balances: Collection, pool: array<string, float>|null}
     */
    public function summaryForCurrentYear(Employee $employee): array
    {
        $shared = $this->isSharedMode();

        // Zero-credit rows are dropped for the same reason the picker hides those types: a
        // breakdown line reading "0 of 0 days" is not a balance, it is a leave type this employee
        // does not have. Unlimited types have no meaningful entitlement to show, so nothing is lost.
        $balances = $this->balancesForCurrentYear($employee)
            ->filter(fn (LeaveBalance $b) => (float) $b->entitled_days > 0)
            ->values();

        $pool = null;
        if ($shared) {
            $year = now(config('app.timezone_local'))->year;
            $poolModel = SharedLeaveBalance::where('employee_id', $employee->id)
                ->where('year', $year)
                ->first();

            if ($poolModel) {
                // FLOAT, not int. The columns are decimal(5,1) and half-day leave puts
                // real halves in them, so casting to int reported 2.5 days used as 2 and handed the
                // employee back half a day of credit they had already spent.
                $entitled = (float) $poolModel->entitled_days;
                $used = (float) $poolModel->used_days;
                $pool = [
                    'entitled_days' => $entitled,
                    'used_days' => $used,
                    'remaining_days' => round($entitled - $used, 1),
                ];
            }
        }

        return [
            'mode' => $shared ? LeaveSetting::MODE_SHARED : LeaveSetting::MODE_NOT_SHARED,
            'balances' => $balances,
            'pool' => $pool,
        ];
    }

    /**
     * Requests visible to this user: HR officers see all; everyone else sees
     * only their own. (The frontend never sends an employee id.)
     */
    public function listRequests(User $user): Collection
    {
        $query = LeaveRequest::query()
            ->with(['employee.user', 'leaveType', 'reviewer', 'canceller'])
            ->latest();

        if (! $user->hasAnyRole(['hr_officer', 'super_admin'])) {
            $employeeId = $user->employee?->id;
            $query->where('employee_id', $employeeId);
        }

        return $query->get();
    }

    /**
     * File a leave request. Recomputes days server-side and enforces the
     * balance — the 422 here is the real gate, not the frontend preview.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Employee $employee, array $data): LeaveRequest
    {
        // Leave can be taken half a day at a time, and `computeDays()` already knew how
        // (0.5, same-day only). Only this call site was passing a hardcoded `false`, so the ability
        // existed and was unreachable. A half day of PAID leave costs 0.5 credits and is paid; a
        // half day of LWOP costs half a day's pay. Both leave the other half expected worked.
        $isHalfDay = (bool) ($data['is_half_day'] ?? false);
        $days = $this->computeDays($data['start_date'], $data['end_date'], $isHalfDay);

        // An UNLIMITED type has no credit pool to check against, so it skips the balance
        // requirement entirely. Note this is `is_unlimited`, not `is_paid`: the two used to be the
        // same switch, which made "at most 10 unpaid days a year" impossible to express. Payroll
        // still docks the day or not on `is_paid` — that is a separate question.
        $type = LeaveType::find($data['leave_type_id']);
        $year = Carbon::parse($data['start_date'])->year;

        // Past-dated filing is gated per leave type (HR-configurable).
        $this->assertBackdatingAllowed($type, $data['start_date']);

        if (! $type || ! $type->is_unlimited) {
            if ($this->isSharedMode()) {
                // Shared mode: every paid leave type draws from ONE pool per
                // employee. Validate against the pool's remaining, not per-type.
                $pool = SharedLeaveBalance::where('employee_id', $employee->id)
                    ->where('year', $year)
                    ->first();

                if (! $pool) {
                    throw ValidationException::withMessages([
                        'leave_type_id' => ["You have no shared leave balance for {$year}."],
                    ]);
                }

                $remaining = (float) $pool->entitled_days - (float) $pool->used_days;
                if ($days > $remaining) {
                    throw ValidationException::withMessages([
                        'end_date' => ["Requested {$days} day(s) exceeds your remaining shared balance of {$remaining} day(s)."],
                    ]);
                }
            } else {
                $balance = LeaveBalance::where('employee_id', $employee->id)
                    ->where('leave_type_id', $data['leave_type_id'])
                    ->where('year', $year)
                    ->first();

                if (! $balance) {
                    throw ValidationException::withMessages([
                        'leave_type_id' => ["You have no leave balance for {$year} for this type."],
                    ]);
                }

                $remaining = (float) $balance->entitled_days - (float) $balance->used_days;
                if ($days > $remaining) {
                    throw ValidationException::withMessages([
                        'end_date' => ["Requested {$days} day(s) exceeds your remaining balance of {$remaining} day(s)."],
                    ]);
                }
            }
        }

        $request = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $data['leave_type_id'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'is_half_day' => $isHalfDay,
            'half_day_period' => $isHalfDay ? ($data['half_day_period'] ?? null) : null,
            'days' => $days,
            'reason' => $data['reason'],
            'status' => 'pending',
        ]);

        $request->load(['employee.user', 'leaveType']);

        $this->audit->log(
            'submitted',
            'Leave',
            "{$request->leaveType?->name} request ("
                . $request->start_date->format('M j') . '–' . $request->end_date->format('M j')
                . ") — {$request->days} day(s)",
            $request,
        );

        return $request;
    }

    /**
     * Approve: mark approved + reviewer, and increment used_days on the matching
     * balance — atomically. Idempotent: a non-pending request is left alone.
     */
    public function approve(LeaveRequest $request, User $reviewer): LeaveRequest
    {
        $wasPending = $request->status === 'pending';

        $result = DB::transaction(function () use ($request, $reviewer) {
            if ($request->status !== 'pending') {
                return $request->load(['employee.user', 'leaveType', 'reviewer']);
            }

            // Only a CAPPED type draws down credits; an unlimited one has none to draw, so it
            // never touches used_days. Keyed on `is_unlimited`, not `is_paid` — an unpaid type
            // with a cap must still consume its allowance, and payroll docks the day either way.
            // Stamp the bucket we deduct from so cancel can reverse it precisely even if HR later
            // flips the global mode.
            $type = LeaveType::find($request->leave_type_id);
            $drawsCredits = (! $type || ! $type->is_unlimited);
            $bucket = ! $drawsCredits
                ? 'none'
                : ($this->isSharedMode() ? 'pool' : 'per_type');

            $request->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'deducted_from' => $bucket,
            ]);

            // A half-day leave narrows that day's shift the moment it is approved, and this
            // method goes on to re-read attendance in the same transaction.
            $this->windows->forget();

            if ($bucket === 'pool') {
                SharedLeaveBalance::where('employee_id', $request->employee_id)
                    ->where('year', $request->start_date->year)
                    ->first()
                    ?->increment('used_days', $request->days);
            } elseif ($bucket === 'per_type') {
                LeaveBalance::where('employee_id', $request->employee_id)
                    ->where('leave_type_id', $request->leave_type_id)
                    ->where('year', $request->start_date->year)
                    ->first()
                    ?->increment('used_days', $request->days);
            }

            $request->load(['employee.user', 'leaveType', 'reviewer']);

            // A day the employee DEMONSTRABLY WORKED must not also spend a credit. Approving a
            // backdated leave over days that already carry a clock-in used to do exactly that:
            // payroll prices those days as worked (attendance is the source of truth), so the
            // request was paid in full AND charged to the balance. Runs inside the transaction,
            // right after the deduction above, so approve() is never observed half-settled.
            $this->attendance->settleLeaveCreditsFor($request);

            $employeeName = $request->employee->user?->name ?? $request->employee->name;
            $this->audit->log(
                'approved',
                'Leave',
                "Approved {$employeeName}'s {$request->leaveType?->name} request",
                $request,
            );

            return $request;
        });

        // A newly-approved leave can retroactively clear an absence/AWOL flag on the
        // covered days (e.g. a backdated leave). Recompute so violation history stays
        // in sync immediately, not only on the next attendance-roster load.
        // Approving a request for days payroll has already settled changes what that
        // period SHOULD have paid. The approval stands (the leave was taken), and the difference
        // is recorded so an adjustment run can settle it rather than relying on someone's memory.
        if ($wasPending) {
            $this->retro->flagIfSettled(
                $request,
                $request->employee_id,
                $request->start_date->format('Y-m-d'),
                $request->end_date->format('Y-m-d'),
                \App\Modules\Hr\Models\RetroFlag::OWED,
                'Leave approved after the period was paid',
            );

            $this->violations->recompute();
        }

        return $result;
    }

    /**
     * Cancel a leave request. Employees may cancel only their OWN request while it
     * is still pending; HR may cancel a pending OR approved request. An approved
     * leave can only be cancelled before it starts, and cancelling it gives the
     * deducted credits back (pool in shared mode, per-type otherwise) and
     * recomputes violations. A reason is required.
     */
    public function cancel(LeaveRequest $request, User $actor, string $reason): LeaveRequest
    {
        $isHr = $actor->hasAnyRole(['hr_officer', 'super_admin']);
        $isOwner = $actor->employee && $actor->employee->id === $request->employee_id;

        if (! $isHr && ! $isOwner) {
            throw new AuthorizationException('You cannot cancel this leave request.');
        }

        if (! in_array($request->status, ['pending', 'approved'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending or approved leave can be cancelled.'],
            ]);
        }

        // Employees can only cancel while pending; cancelling an approved leave is
        // an HR action.
        if (! $isHr && $request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['You can only cancel a request while it is pending. Ask HR to cancel an approved leave.'],
            ]);
        }

        $wasApproved = $request->status === 'approved';

        // An approved leave can only be cancelled before it starts (Manila day).
        if ($wasApproved) {
            $tz = config('app.timezone_local');
            $today = Carbon::now($tz)->startOfDay();
            $start = Carbon::parse($request->start_date->toDateString(), $tz)->startOfDay();
            if ($start->lte($today)) {
                throw ValidationException::withMessages([
                    'start_date' => ['This leave has already started and can no longer be cancelled.'],
                ]);
            }
        }

        DB::transaction(function () use ($request, $actor, $reason, $wasApproved) {
            if ($wasApproved) {
                $this->restoreBalance($request);
            }

            $request->update([
                'status' => 'cancelled',
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            $this->windows->forget();

            $request->load(['employee.user', 'leaveType']);
            $this->audit->log(
                'cancelled',
                'Leave',
                "Cancelled {$request->leaveType?->name} request",
                $request,
            );
        });

        // Removing an approved leave can re-open an absence/AWOL flag on its days.
        if ($wasApproved) {
            $this->violations->recompute();
        }

        return $request->load(['employee.user', 'leaveType', 'reviewer', 'canceller']);
    }

    /**
     * Give back the credits an approval consumed. Reverses the EXACT bucket stamped at
     * approval (`deducted_from`), so a mode flip between approve and reversal can't
     * restore the wrong table. Legacy rows (approved before stamping) fall back to the
     * paid-type + current-mode guess.
     */
    private function restoreBalance(LeaveRequest $request): void
    {
        $outstanding = max(0.0, (float) $request->days - $this->attendance->refundedCreditFor($request));
        $bucket = $request->deducted_from;
        if ($bucket === null) {
            $type = LeaveType::find($request->leave_type_id);
            $drawsCredits = (! $type || ! $type->is_unlimited);
            $bucket = ! $drawsCredits
                ? 'none'
                : ($this->isSharedMode() ? 'pool' : 'per_type');
        }

        if ($bucket === 'pool') {
            $pool = SharedLeaveBalance::where('employee_id', $request->employee_id)
                ->where('year', $request->start_date->year)
                ->first();
            if ($pool) {
                $pool->update(['used_days' => max(0, (float) $pool->used_days - $outstanding)]);
            }
        } elseif ($bucket === 'per_type') {
            $balance = LeaveBalance::where('employee_id', $request->employee_id)
                ->where('leave_type_id', $request->leave_type_id)
                ->where('year', $request->start_date->year)
                ->first();
            if ($balance) {
                $balance->update(['used_days' => max(0, (float) $balance->used_days - $outstanding)]);
            }
        }
    }

    /**
     * Undo the decision — back to pending, so HR can rule on it again. Unlike cancel()
     * (final, and only before the leave starts), a reopened request is still live, which
     * is what makes it the right tool for a mistaken approval on a date already past.
     */
    public function reopen(LeaveRequest $request, ?string $reason = null): LeaveRequest
    {
        if ($request->status === 'pending') {
            throw ValidationException::withMessages([
                'status' => ['This request is still pending — there is nothing to undo.'],
            ]);
        }

        $wasApproved = $request->status === 'approved';

        if ($wasApproved) {
            $this->freeze->assertOpen(
                $request->employee_id,
                $request->start_date->toDateString(),
                $request->end_date->toDateString(),
                'reopen this leave request',
            );
        }

        DB::transaction(function () use ($request, $reason, $wasApproved) {
            if ($wasApproved) {
                // Order matters: restoreBalance() reads the stamps to work out what is still
                // outstanding, so they can only be cleared once it has.
                $this->restoreBalance($request);
                $this->attendance->clearLeaveCreditStamps($request);
            }

            $request->update([
                'status' => 'pending',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_note' => null,
                'deducted_from' => null,
                'cancelled_by' => null,
                'cancelled_at' => null,
                'cancel_reason' => null,
            ]);

            $this->windows->forget();

            $request->load(['employee.user', 'leaveType']);
            $employeeName = $request->employee->user?->name ?? $request->employee->name;
            $this->audit->log(
                'reopened',
                'Leave',
                "Reopened {$employeeName}'s {$request->leaveType?->name} request"
                    . ($reason ? " — {$reason}" : ''),
                $request,
            );
        });

        // The days stop being excused leave, so an absence/AWOL flag may come back.
        if ($wasApproved) {
            $this->violations->recompute();
        }

        return $request->load(['employee.user', 'leaveType', 'reviewer']);
    }

    /** Reject: mark rejected + reviewer + reason. Balance is untouched. */
    public function reject(LeaveRequest $request, User $reviewer, ?string $reason = null): LeaveRequest
    {
        if ($request->status === 'pending') {
            $request->update([
                'status' => 'rejected',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $reason,
            ]);

            $this->windows->forget();

            $request->load(['employee.user', 'leaveType']);
            $employeeName = $request->employee->user?->name ?? $request->employee->name;
            $this->audit->log(
                'declined',
                'Leave',
                "Declined {$employeeName}'s {$request->leaveType?->name} request",
                $request,
            );
        }

        return $request->load(['employee.user', 'leaveType', 'reviewer']);
    }
}
