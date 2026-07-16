<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
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
     * Requests visible to this user: HR officers see all; everyone else sees
     * only their own. (The frontend never sends an employee id.)
     */
    public function listRequests(User $user): Collection
    {
        $query = LeaveRequest::query()
            ->with(['employee.user', 'leaveType', 'reviewer'])
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

            $request->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            // Only paid leave draws down a credit balance; unpaid leave (LWOP) is
            // deducted from pay by payroll, so it never touches used_days.
            $type = LeaveType::find($request->leave_type_id);
            if (! $type || $type->is_paid) {
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
