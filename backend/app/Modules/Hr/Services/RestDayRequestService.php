<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\RestDayRequest;
use App\Modules\Hr\Models\RetroFlag;
use App\Modules\Hr\Services\Concerns\CancelsOwnRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Rest-day work requests — permission to work a day the schedule says is a rest day.
 *
 * The flow is deliberately unlike every other request in this module: the employee does not fill
 * in a form, they press Time in on a Saturday. The server refuses to write attendance and files
 * this instead, stamped with the moment of the press. HR approving it writes the attendance row at
 * THAT moment — not at the moment of approval — so the review delay costs the employee nothing.
 *
 * Nothing downstream needed teaching. Rest-day premium already prices any attendance row landing
 * on a non-workday (AttendanceService::restDayWorkedMinutesFor), and violations already skip rest
 * days entirely. This service is only the gate on such a row existing.
 */
class RestDayRequestService
{
    use CancelsOwnRequest;

    public function __construct(
        private readonly ScheduleService $schedule,
        private readonly AuditService $audit,
        private readonly PayrollFreezeService $freeze,
        private readonly RetroFlagService $retro,
    ) {}

    /** HR sees all; an employee sees only their own. Stale pending rows expire on the way past. */
    public function list(User $user): Collection
    {
        $this->expireStale();

        $query = RestDayRequest::with(['employee.user', 'reviewer', 'canceller'])
            ->orderByDesc('work_date')
            ->latest();

        if (! $user->hasAnyRole(['hr_officer', 'super_admin'])) {
            $query->where('employee_id', $user->employee?->id);
        }

        return $query->get();
    }

    /**
     * The employee's live request for a Manila date, if any — what the time clock reads to decide
     * between offering the button, saying "waiting for HR", and saying why it was refused.
     *
     * Includes decided requests for the day so the panel can explain a rejection rather than
     * silently re-offering the same button.
     */
    public function forDate(Employee $employee, string $date): ?RestDayRequest
    {
        $this->expireStale($employee);

        return RestDayRequest::where('employee_id', $employee->id)
            ->where('work_date', $date)
            ->latest('id')
            ->first();
    }

    /**
     * File one. `$date` is always the caller's today — a rest-day request IS the press of the
     * time-in button, so there is no version of it that can be filed in advance.
     */
    public function create(Employee $employee, string $date, ?string $reason = null): RestDayRequest
    {
        if ($employee->isManagement()) {
            throw ValidationException::withMessages([
                'work_date' => ['Management employees are exempt from attendance.'],
            ]);
        }

        $tz = config('app.timezone_local');
        $day = Carbon::parse($date, $tz)->startOfDay();

        if ($this->schedule->effectiveFor($employee, $day)['is_workday'] ?? true) {
            throw ValidationException::withMessages([
                'work_date' => ['This is one of your scheduled workdays — just time in as usual.'],
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

        $live = RestDayRequest::where('employee_id', $employee->id)
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

        $req = RestDayRequest::create([
            'employee_id' => $employee->id,
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
            'Rest-day work request (' . $req->work_date->format('M j') . ')',
            $req,
        );

        return $req;
    }

    /**
     * Approve — and write the attendance row the refused clock-in would have written, stamped
     * at `requested_at`.
     *
     * The employee then clocks OUT through the ordinary time clock: `openRow()` resolves a row
     * with a time-in and no time-out, so no special case is needed on the way back out.
     */
    public function approve(RestDayRequest $request, User $reviewer): RestDayRequest
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
        // Present, not `rest_day`: `rest_day` is the status for a rest day NOT worked. There is
        // no shift to be late against on a day the schedule does not cover, so no lateness is
        // computed either.
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
            "Approved {$name}'s rest-day work on " . $request->work_date->format('M j')
                . ' — timed in at ' . $request->requested_at->timezone(config('app.timezone_local'))->format('g:i A'),
            $request,
        );

        // A worked rest day earns a premium, so approving one for a period payroll has already
        // settled means the employee is OWED money that run did not pay. The approval stands;
        // the difference is recorded instead of being left to somebody's memory.
        $this->retro->flagIfSettled(
            $request,
            $request->employee_id,
            $date,
            null,
            RetroFlag::OWED,
            'Rest-day work approved after the period was paid',
        );

        return $request;
    }

    public function reject(RestDayRequest $request, User $reviewer, string $reason): RestDayRequest
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
            "Declined {$name}'s rest-day work request",
            $request,
        );

        return $request;
    }

    /**
     * Undo the decision — back to pending, and the attendance row the approval created goes
     * with it.
     *
     * Refused once the shift has been clocked OUT: at that point the day was genuinely worked
     * and its hours are real, so deleting the row would erase attendance rather than undo an
     * approval. That correction belongs on the Attendance screen, where it is visible as one.
     */
    public function reopen(RestDayRequest $request, ?string $reason = null): RestDayRequest
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
                'reopen this rest-day request',
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
            "Reopened {$name}'s rest-day work request" . ($reason ? " — {$reason}" : ''),
            $request,
        );

        return $request;
    }

    /** The employee withdraws their own request while HR has yet to decide it. */
    public function cancel(RestDayRequest $request, User $actor, string $reason): RestDayRequest
    {
        $this->applyCancellation($request, $actor, $reason, 'rest-day request');

        $request->load(['employee.user', 'reviewer', 'canceller']);
        $name = $request->employee?->user?->name ?? 'an employee';

        $this->audit->log(
            'cancelled',
            'Attendance',
            "Cancelled {$name}'s rest-day work request for " . $request->work_date->format('M j') . " — {$reason}",
            $request,
        );

        return $request;
    }

    private function assertPending(RestDayRequest $request, string $action): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["Cannot {$action} a request that is {$request->status}."],
            ]);
        }
    }

    /** Approving a stale request would back-date a shift — see RestDayRequest::EXPIRES_AFTER_HOURS. */
    private function assertFresh(RestDayRequest $request): void
    {
        if (! $request->isStale()) {
            return;
        }

        $this->markExpired($request);

        throw ValidationException::withMessages([
            'status' => [
                'This request expired — it was filed more than ' . RestDayRequest::EXPIRES_AFTER_HOURS
                    . ' hours ago. Approving it now would back-date a shift; add the day on the Attendance screen instead.',
            ],
        ]);
    }

    /**
     * Move pending requests past their window to `expired`.
     *
     * Lazily, on read, rather than on a schedule: a cron that fails leaves approvable back-dated
     * shifts sitting in the queue, and there is no moment an expired request matters except the
     * moment somebody looks at it.
     */
    private function expireStale(?Employee $employee = null): void
    {
        $cutoff = now()->subHours(RestDayRequest::EXPIRES_AFTER_HOURS);

        RestDayRequest::where('status', 'pending')
            ->where('requested_at', '<', $cutoff)
            ->when($employee, fn ($q) => $q->where('employee_id', $employee->id))
            ->update(['status' => 'expired']);
    }

    private function markExpired(RestDayRequest $request): void
    {
        $request->update(['status' => 'expired']);
    }
}
