<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Services\Concerns\CancelsOwnRequest;
use App\Modules\Hr\Services\Concerns\PagesQueues;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Half-day requests — a standalone, always-unpaid half-day off (no leave credits).
 * HR approves; payroll deducts 0.5 day for each APPROVED one (see
 * AttendanceService::unpaidDaysFor). Mirrors the leave approval flow.
 */
class HalfDayRequestService
{
    use CancelsOwnRequest;
    use PagesQueues;

    public function __construct(
        private readonly AuditService $audit,
        private readonly PayrollFreezeService $freeze,
        private readonly RetroFlagService $retro,
        private readonly HalfDayWindowService $windows,
        private readonly AttendancePolicyService $policy,
    ) {}

    /** HR sees all; an employee sees only their own. */
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
        // `employee.workSchedule` so the resource can resolve each row's worked window without
        // one schedule query per request.
        $query = HalfDayRequest::with(['employee.user', 'employee.workSchedule', 'reviewer', 'canceller']);

        if (! $user->hasAnyRole(['hr_officer', 'super_admin'])) {
            $query->where('employee_id', $user->employee?->id);
        }

        return $this->paginateQueue(
            $this->scopeToWindow($query, $filters, 'work_date'),
            $filters,
            ['period', 'reason'],
            priorityStatuses: ['pending'],
            recencyColumn: 'work_date',
        );
    }

    /** @param array<string, mixed> $data */
    public function create(Employee $employee, array $data): HalfDayRequest
    {
        if ($employee->managementExemptOn($data['work_date'], (bool) $this->policy->get()->management_auto_attendance)) {
            throw ValidationException::withMessages([
                'work_date' => ['Management employees are exempt from attendance.'],
            ]);
        }

        $req = HalfDayRequest::create([
            'employee_id' => $employee->id,
            'work_date' => $data['work_date'],
            'period' => $data['period'],
            'reason' => $data['reason'],
            'status' => 'pending',
        ])->load('employee.user');

        $this->audit->log(
            'submitted',
            'Leave',
            'Half-day request (' . $req->work_date->format('M j') . ' · ' . $req->period . ')',
            $req,
        );

        return $req;
    }

    public function approve(HalfDayRequest $request, User $reviewer): HalfDayRequest
    {
        $this->assertPending($request, 'approve');

        $request->update([
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        // This date is now a half day: whatever already read it as a whole one is stale.
        $this->windows->forget();

        $request->load(['employee.user', 'reviewer']);
        $employeeName = $request->employee->user?->name ?? $request->employee->name;
        $this->audit->log(
            'approved',
            'Leave',
            "Approved {$employeeName}'s half-day request",
            $request,
        );

        // A half-day shortens a paid day, so approving one for a cutoff payroll has
        // already settled means the employee was paid for time the record now says they did not
        // work. The approval stands; the difference is recorded rather than left to memory.
        // OVERPAID, not OWED: recovering it is a wage deduction needing consent (Art. 113).
        $this->retro->flagIfSettled(
            $request,
            $request->employee_id,
            $request->work_date->format('Y-m-d'),
            null,
            \App\Modules\Hr\Models\RetroFlag::OVERPAID,
            'Half-day approved after the period was paid',
        );

        return $request;
    }

    public function reject(HalfDayRequest $request, User $reviewer, string $reason): HalfDayRequest
    {
        $this->assertPending($request, 'reject');

        $request->update([
            'status' => 'rejected',
            'review_note' => $reason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $this->windows->forget();

        $request->load(['employee.user', 'reviewer']);
        $employeeName = $request->employee->user?->name ?? $request->employee->name;
        $this->audit->log(
            'declined',
            'Leave',
            "Declined {$employeeName}'s half-day request",
            $request,
        );

        return $request;
    }

    /**
     * Undo the decision — back to pending. An approved half-day costs the employee
     * 0.5 unpaid day, so this is refused once payroll has settled that date.
     */
    public function reopen(HalfDayRequest $request, ?string $reason = null): HalfDayRequest
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
                'reopen this half-day request',
            );
        }

        $request->update([
            'status' => 'pending',
            'review_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        $this->windows->forget();

        $request->load(['employee.user', 'reviewer']);
        $employeeName = $request->employee->user?->name ?? $request->employee->name;
        $this->audit->log(
            'reopened',
            'Leave',
            "Reopened {$employeeName}'s half-day request" . ($reason ? " — {$reason}" : ''),
            $request,
        );

        return $request;
    }

    private function assertPending(HalfDayRequest $request, string $action): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["Cannot {$action} a request that is {$request->status}."],
            ]);
        }
    }

    /**
     * The employee withdraws their own request while HR has yet to decide it.
     *
     * Pending only, so nothing downstream has to be unwound: an APPROVED half-day request has already
     * changed what the day is worth, and reversing that is an HR act with a paper trail.
     */
    public function cancel(HalfDayRequest $request, User $actor, string $reason): HalfDayRequest
    {
        $this->applyCancellation($request, $actor, $reason, 'half-day request');
        $this->windows->forget();

        $request->load(['employee.user', 'reviewer', 'canceller']);
        $name = $request->employee?->user?->name ?? 'an employee';

        $this->audit->log(
            'cancelled',
            'Leave',
            "Cancelled {$name}'s half-day request for " . $request->work_date->format('M j') . " — {$reason}",
            $request,
        );

        return $request;
    }
}
