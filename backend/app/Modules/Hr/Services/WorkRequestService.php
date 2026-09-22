<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\RetroFlag;
use App\Modules\Hr\Models\WorkRequest;
use App\Modules\Hr\Services\Concerns\CancelsOwnRequest;
use App\Modules\Hr\Services\Concerns\PagesQueues;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Work requests — permission to work a day nobody was expected in on. */
class WorkRequestService
{
    use CancelsOwnRequest;
    use PagesQueues;

    public function __construct(
        private readonly ScheduleService $schedule,
        private readonly AuditService $audit,
        private readonly PayrollFreezeService $freeze,
        private readonly RetroFlagService $retro,
        private readonly AttendancePolicyService $policy,
        private readonly WorkSuspensionService $suspensions,
    ) {}

    /** HR sees all; an employee sees only their own. Stale pending rows expire on the way past. */
    /**
     * One page of the queue. HR sees everyone's; anybody else sees only their own, and that
     * scope is applied BEFORE the filters so no search can widen it.
     *
     * Ordered by the day the request is ABOUT rather than the day it was filed — that is the
     * column the screen shows and sorts were always meant to follow. Pending rows lead it,
     * because paging is what decides whether a decision waiting three weeks is on page 1 or
     * page 4.
     *
     * @param  array<string, mixed>  $filters  search, status, page, per_page
     */
    public function list(User $user, array $filters = []): LengthAwarePaginator
    {
        $this->expireStale();

        $query = WorkRequest::with(['employee.user', 'reviewer', 'canceller']);

        if (! $user->hasAnyRole(['hr_officer', 'super_admin'])) {
            $query->where('employee_id', $user->employee?->id);
        }

        return $this->paginateQueue(
            $this->scopeToWindow($query, $filters, 'work_date'),
            $filters,
            ['type', 'reason'],
            priorityStatuses: ['pending'],
            recencyColumn: 'work_date',
        );
    }

    /**
     * The employee's live request for a Manila date, if any — what the time clock reads to decide
     * between offering the button, saying "waiting for HR", and saying why it was refused.
     */
    public function forDate(Employee $employee, string $date): ?WorkRequest
    {
        $this->expireStale($employee);

        return WorkRequest::where('employee_id', $employee->id)
            ->where('work_date', $date)
            ->latest('id')
            ->first();
    }

    /** Exempt from the clock on this date — the person's flag AND the company rule. */
    private function attendanceExempt(Employee $employee, string $date): bool
    {
        return $employee->managementExemptOn($date, (bool) $this->policy->get()->management_auto_attendance);
    }

    /** Which kind of request a date calls for, or null when the employee may simply time in. */
    public function requiredTypeFor(Employee $employee, string $date): ?string
    {
        if ($this->attendanceExempt($employee, $date)) {
            return null;
        }

        $day = Carbon::parse($date, config('app.timezone_local'))->startOfDay();

        if (! ($this->schedule->effectiveFor($employee, $day)['is_workday'] ?? true)) {
            return $this->policy->get()->rest_day_work_requires_request
                ? WorkRequest::TYPE_REST_DAY
                : null;
        }

        $suspension = $this->suspensions->forDate($date);

        return $suspension && $suspension->work_requires_request
            ? WorkRequest::TYPE_SUSPENSION
            : null;
    }

    /**
     * File one. `$date` is always the caller's today — a work request IS the press of the time-in
     * button, so there is no version of it that can be filed in advance.
     */
    public function create(Employee $employee, string $date, ?string $reason = null): WorkRequest
    {
        if ($this->attendanceExempt($employee, $date)) {
            throw ValidationException::withMessages([
                'work_date' => ['Management employees are exempt from attendance.'],
            ]);
        }

        $type = $this->requiredTypeFor($employee, $date);

        if ($type === null) {
            throw ValidationException::withMessages([
                'work_date' => ['You can work this day without asking — just time in as usual.'],
            ]);
        }

        // Already clocked in for this date by some other route (HR entered it, or a previous
        // approval). There is nothing left to ask permission for.
        $existing = Attendance::where('employee_id', $employee->id)
            ->where('work_date', $date)
            ->whereNotNull('time_in')
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'work_date' => ['You are already timed in for this day.'],
            ]);
        }

        $this->expireStale($employee);

        $live = WorkRequest::where('employee_id', $employee->id)
            ->where('work_date', $date)
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($live) {
            throw ValidationException::withMessages([
                'work_date' => [
                    $live->status === 'pending'
                        ? 'You already have a request for this day waiting for HR.'
                        : 'HR has already approved you to work this day.',
                ],
            ]);
        }

        $req = WorkRequest::create([
            'employee_id' => $employee->id,
            'type' => $type,
            'work_date' => $date,
            // The punch. Written here and never recomputed — this column is the whole point
            // of the feature, and an approval that stamped `now()` instead would silently
            // charge the employee for however long HR took to look at it.
            'requested_at' => now(),
            'reason' => $reason,
            'status' => 'pending',
        ])->load('employee.user');

        $this->audit->log(
            'submitted',
            'Attendance',
            'Work request — ' . $req->dayLabel() . ' (' . $req->work_date->format('M j') . ')',
            $req,
        );

        return $req;
    }

    /**
     * Approve — and write the attendance row the refused clock-in would have written, stamped
     * at `requested_at`.
     */
    public function approve(WorkRequest $request, User $reviewer): WorkRequest
    {
        $this->assertPending($request, 'approve');
        $this->assertFresh($request);

        $employee = $request->employee;

        if (! $employee) {
            throw ValidationException::withMessages([
                'status' => ['This request has no employee record any more.'],
            ]);
        }

        $date = $request->work_date->toDateString();

        // Somebody else got there first — an HR correction, or a second approval racing this
        // one. Overwriting a real punch with this one would move a time-in nobody asked to move.
        $clash = Attendance::where('employee_id', $employee->id)
            ->where('work_date', $date)
            ->whereNotNull('time_in')
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'status' => ['There is already a time-in recorded for this employee on this date.'],
            ]);
        }

        // withTrashed: `hr_attendance` is uniquely indexed on (employee_id, work_date) WITHOUT
        // deleted_at, so a soft-deleted row for this day still occupies the slot. Inserting
        // beside it raises a 1062 rather than creating a second row — verified, not theorised.
        $attendance = Attendance::withTrashed()->firstOrNew([
            'employee_id' => $employee->id,
            'work_date' => $date,
        ]);
        // A resurrected row starts clean: whatever a previous life recorded is not this shift,
        // and half of it surviving is worse than either outcome.
        $attendance->deleted_at = null;
        $attendance->time_out = null;
        $attendance->hours_worked = 0;
        // `requested_at` is already UTC (stamped with now()), so it is stored as-is — unlike
        // the Manila-tz Carbons elsewhere in the module, which must be converted first.
        $attendance->time_in = $request->requested_at;
        // Present, and never `late`, for BOTH types. On a rest day there is no shift to be late
        // against at all. On a suspended day there nominally is one, but work was cancelled —
        // holding a volunteer to the punctuality of a shift the company called off would charge
        // them for coming in. `rest_day` is not used either: that status means a rest day NOT
        // worked.
        $attendance->status = 'present';
        $attendance->source = Attendance::SOURCE_MANUAL;
        $attendance->save();

        $request->update([
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $request->load(['employee.user', 'reviewer']);
        $name = $request->employee->user?->name ?? $request->employee->name;

        $this->audit->log(
            'approved',
            'Attendance',
            "Approved {$name}'s work on the " . $request->dayLabel() . ' of '
                .$request->work_date->format('M j')
                . ' — timed in at ' . $request->requested_at->timezone(config('app.timezone_local'))->format('g:i A'),
            $request,
        );

        // A worked rest day earns a premium and a worked suspended day is paid as an ordinary
        // day, so approving either for a period payroll has already settled means the employee
        // is OWED money that run did not pay. The approval stands; the difference is recorded
        // instead of being left to somebody's memory.
        $this->retro->flagIfSettled(
            $request,
            $request->employee_id,
            $date,
            null,
            RetroFlag::OWED,
            ucfirst($request->dayLabel()) . ' work approved after the period was paid',
        );

        return $request;
    }

    public function reject(WorkRequest $request, User $reviewer, string $reason): WorkRequest
    {
        $this->assertPending($request, 'reject');

        $request->update([
            'status' => 'rejected',
            'review_note' => $reason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $request->load(['employee.user', 'reviewer']);
        $name = $request->employee?->user?->name ?? $request->employee?->name;

        $this->audit->log(
            'declined',
            'Attendance',
            "Declined {$name}'s request to work the " . $request->dayLabel() . ' of '
                .$request->work_date->format('M j'),
            $request,
        );

        return $request;
    }

    /**
     * Undo the decision — back to pending, and the attendance row the approval created goes
     * with it.
     */
    public function reopen(WorkRequest $request, ?string $reason = null): WorkRequest
    {
        if ($request->status === 'pending') {
            throw ValidationException::withMessages([
                'status' => ['This request is still pending — there is nothing to undo.'],
            ]);
        }

        $date = $request->work_date->toDateString();

        if ($request->status === 'approved') {
            $this->freeze->assertOpen(
                $request->employee_id,
                $date,
                null,
                'reopen this work request',
            );

            $attendance = Attendance::where('employee_id', $request->employee_id)
                ->where('work_date', $date)
                ->first();

            if ($attendance?->time_out !== null) {
                throw ValidationException::withMessages([
                    'status' => [
                        'This shift has already been clocked out — the day was worked. Correct it on the Attendance screen instead.',
                    ],
                ]);
            }

            // forceDelete, not a soft delete: this row exists ONLY because of the approval being
            // undone, and `hr_attendance`'s unique (employee_id, work_date) index does not
            // consider deleted_at — a soft-deleted row would silently block the next approval
            // for the same day with a 1062. The audit line below is the trail; the row is not.
            $attendance?->forceDelete();
        }

        $request->update([
            'status' => 'pending',
            'review_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        $request->load(['employee.user', 'reviewer']);
        $name = $request->employee?->user?->name ?? $request->employee?->name;

        $this->audit->log(
            'reopened',
            'Attendance',
            "Reopened {$name}'s work request".($reason ? " — {$reason}" : ''),
            $request,
        );

        return $request;
    }

    /** The employee withdraws their own request while HR has yet to decide it. */
    public function cancel(WorkRequest $request, User $actor, string $reason): WorkRequest
    {
        $this->applyCancellation($request, $actor, $reason, 'work request');

        $request->load(['employee.user', 'reviewer', 'canceller']);
        $name = $request->employee?->user?->name ?? 'an employee';

        $this->audit->log(
            'cancelled',
            'Attendance',
            "Cancelled {$name}'s work request for ".$request->work_date->format('M j')." — {$reason}",
            $request,
        );

        return $request;
    }

    private function assertPending(WorkRequest $request, string $action): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["Cannot {$action} a request that is {$request->status}."],
            ]);
        }
    }

    /** Approving a stale request would back-date a shift — see WorkRequest::EXPIRES_AFTER_HOURS. */
    private function assertFresh(WorkRequest $request): void
    {
        if (! $request->isStale()) {
            return;
        }

        $this->markExpired($request);

        throw ValidationException::withMessages([
            'status' => [
                'This request expired — it was filed more than ' . WorkRequest::EXPIRES_AFTER_HOURS
                    . ' hours ago. Approving it now would back-date a shift; add the day on the Attendance screen instead.',
            ],
        ]);
    }

    /** Move pending requests past their window to `expired`. */
    private function expireStale(?Employee $employee = null): void
    {
        $cutoff = now()->subHours(WorkRequest::EXPIRES_AFTER_HOURS);

        WorkRequest::where('status', 'pending')
            ->where('requested_at', '<', $cutoff)
            ->when($employee, fn ($q) => $q->where('employee_id', $employee->id))
            ->update(['status' => 'expired']);
    }

    private function markExpired(WorkRequest $request): void
    {
        $request->update(['status' => 'expired']);
    }
}
