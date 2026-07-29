<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveSetting;
use App\Modules\Hr\Models\LeaveType;
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
    ) {}

    /**
     * Leave days = weekdays (Mon–Fri) between start and end, inclusive, MINUS any
     * non-working holidays in range (Slice 15 — §5.5c). Half-day = 0.5, single day.
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

    /** The employee's balances for the current (Manila) year, with the type. */
    public function balancesForCurrentYear(Employee $employee): Collection
    {
        $year = now(config('app.timezone_local'))->year;

        return $employee->leaveBalances()
            ->with('leaveType')
            ->where('year', $year)
            ->get();
    }

    /**
     * The employee's leave summary for the current (Manila) year: the active
     * allotment mode, their per-type balances (for not-shared display) and, in
     * shared mode, the single pool balance. The frontend renders whichever the
     * mode calls for.
     *
     * @return array{mode: string, balances: Collection, pool: array<string, int>|null}
     */
    public function summaryForCurrentYear(Employee $employee): array
    {
        $shared = $this->isSharedMode();
        $balances = $this->balancesForCurrentYear($employee);

        $pool = null;
        if ($shared) {
            $year = now(config('app.timezone_local'))->year;
            $poolModel = SharedLeaveBalance::where('employee_id', $employee->id)
                ->where('year', $year)
                ->first();

            if ($poolModel) {
                $entitled = (int) $poolModel->entitled_days;
                $used = (int) $poolModel->used_days;
                $pool = [
                    'entitled_days' => $entitled,
                    'used_days' => $used,
                    'remaining_days' => $entitled - $used,
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
        // Leave is full-day only — half-days are a separate (always unpaid) request.
        $days = $this->computeDays($data['start_date'], $data['end_date'], false);

        // Unpaid leave (LWOP) has no credit pool — it's deducted from pay, not a
        // balance — so it skips the balance requirement entirely. Paid leave still
        // needs a balance with enough remaining credits.
        $type = LeaveType::find($data['leave_type_id']);
        $year = Carbon::parse($data['start_date'])->year;

        // Past-dated filing is gated per leave type (HR-configurable).
        $this->assertBackdatingAllowed($type, $data['start_date']);

        if (! $type || $type->is_paid) {
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
            'is_half_day' => false,
            'half_day_period' => null,
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

            // Only paid leave draws down a credit balance; unpaid leave (LWOP) is
            // deducted from pay by payroll, so it never touches used_days. Stamp
            // the bucket we deduct from so cancel can reverse it precisely even if
            // HR later flips the global mode.
            $type = LeaveType::find($request->leave_type_id);
            $paid = (! $type || $type->is_paid);
            $bucket = ! $paid
                ? 'none'
                : ($this->isSharedMode() ? 'pool' : 'per_type');

            $request->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'deducted_from' => $bucket,
            ]);

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
        if ($wasPending) {
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
        $bucket = $request->deducted_from;
        if ($bucket === null) {
            $type = LeaveType::find($request->leave_type_id);
            $paid = (! $type || $type->is_paid);
            $bucket = ! $paid
                ? 'none'
                : ($this->isSharedMode() ? 'pool' : 'per_type');
        }

        if ($bucket === 'pool') {
            $pool = SharedLeaveBalance::where('employee_id', $request->employee_id)
                ->where('year', $request->start_date->year)
                ->first();
            if ($pool) {
                $pool->update(['used_days' => max(0, (float) $pool->used_days - (float) $request->days)]);
            }
        } elseif ($bucket === 'per_type') {
            $balance = LeaveBalance::where('employee_id', $request->employee_id)
                ->where('leave_type_id', $request->leave_type_id)
                ->where('year', $request->start_date->year)
                ->first();
            if ($balance) {
                $balance->update(['used_days' => max(0, (float) $balance->used_days - (float) $request->days)]);
            }
        }
    }

    /**
     * Undo the decision — back to pending, so HR can rule on it again. Unlike cancel()
     * (final, and only before the leave starts), a reopened request is still live, which
     * is what makes it the right tool for a mistaken approval on a date already past.
     *
     * Re-approving re-deducts, so the credits an approval consumed are given back here,
     * and violations recompute because the covered days stop counting as excused leave.
     * Refused once payroll has settled any day the leave covers.
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
                $this->restoreBalance($request);
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
