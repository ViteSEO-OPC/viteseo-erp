<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Services\Concerns\CancelsOwnRequest;
use App\Modules\Hr\Services\Concerns\PagesQueues;
use App\Modules\Hr\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Early-out (undertime) requests — a standalone, always-unpaid partial day: the
 * employee leaves before their scheduled end. HR approves; payroll deducts the
 * undertime fraction for each APPROVED one (see AttendanceService::unpaidDaysFor).
 * Mirrors the half-day approval flow, with a schedule-window check on filing.
 */
class EarlyOutRequestService
{
    use CancelsOwnRequest;
    use PagesQueues;

    public function __construct(
        private readonly AuditService $audit,
        private readonly ScheduleService $schedule,
        private readonly PayrollFreezeService $freeze,
        private readonly RetroFlagService $retro,
        private readonly HalfDayWindowService $halfDays,
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
        $query = EarlyOutRequest::with(['employee.user', 'reviewer', 'canceller']);

        if (! $user->hasAnyRole(['hr_officer', 'super_admin'])) {
            $query->where('employee_id', $user->employee?->id);
        }

        return $this->paginateQueue(
            $query,
            $filters,
            ['reason'],
            priorityStatuses: ['pending'],
            recencyColumn: 'work_date',
        );
    }

    /** @param array<string, mixed> $data */
    public function create(Employee $employee, array $data): EarlyOutRequest
    {
        if ($employee->managementExemptOn($data['work_date'], (bool) $this->policy->get()->management_auto_attendance)) {
            throw ValidationException::withMessages([
                'work_date' => ['Management employees are exempt from attendance.'],
            ]);
        }

        $this->assertWithinShift($employee, $data['work_date'], $data['early_out_time']);

        $req = EarlyOutRequest::create([
            'employee_id' => $employee->id,
            'work_date' => $data['work_date'],
            'early_out_time' => $data['early_out_time'],
            'reason' => $data['reason'],
            'status' => 'pending',
        ])->load('employee.user');

        $this->audit->log(
            'submitted',
            'Leave',
            'Early-out request (' . $req->work_date->format('M j') . ' · ' . substr((string) $req->early_out_time, 0, 5) . ')',
            $req,
        );

        return $req;
    }

    public function approve(EarlyOutRequest $request, User $reviewer): EarlyOutRequest
    {
        $this->assertPending($request, 'approve');

        $request->update([
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $request->load(['employee.user', 'reviewer']);
        $employeeName = $request->employee->user?->name ?? $request->employee->name;
        $this->audit->log(
            'approved',
            'Leave',
            "Approved {$employeeName}'s early-out request",
            $request,
        );

        // A early-out shortens a paid day, so approving one for a cutoff payroll has
        // already settled means the employee was paid for time the record now says they did not
        // work. The approval stands; the difference is recorded rather than left to memory.
        // OVERPAID, not OWED: recovering it is a wage deduction needing consent (Art. 113).
        $this->retro->flagIfSettled(
            $request,
            $request->employee_id,
            $request->work_date->format('Y-m-d'),
            null,
            \App\Modules\Hr\Models\RetroFlag::OVERPAID,
            'Early-out approved after the period was paid',
        );

        return $request;
    }

    public function reject(EarlyOutRequest $request, User $reviewer, string $reason): EarlyOutRequest
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
        $this->audit->log(
            'declined',
            'Leave',
            "Declined {$employeeName}'s early-out request",
            $request,
        );

        return $request;
    }

    /**
     * Undo the decision — back to pending. An approved early-out docks the undertime
     * fraction, so this is refused once payroll has settled that date.
     */
    public function reopen(EarlyOutRequest $request, ?string $reason = null): EarlyOutRequest
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
                'reopen this early-out request',
            );
        }

        $request->update([
            'status' => 'pending',
            'review_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        $request->load(['employee.user', 'reviewer']);
        $employeeName = $request->employee->user?->name ?? $request->employee->name;
        $this->audit->log(
            'reopened',
            'Leave',
            "Reopened {$employeeName}'s early-out request" . ($reason ? " — {$reason}" : ''),
            $request,
        );

        return $request;
    }

    /**
     * The date must be a scheduled workday and the time must fall strictly inside
     * that day's shift (after start, before end) — an early-out only makes sense
     * against hours the person was expected to work.
     */
    private function assertWithinShift(Employee $employee, string $workDate, string $time): void
    {
        $tz = config('app.timezone_local');
        $day = Carbon::parse($workDate, $tz);
        $eff = $this->schedule->effectiveFor($employee, $day);

        if (! ($eff['is_workday'] ?? false)) {
            throw ValidationException::withMessages([
                'work_date' => ['That date is not a scheduled workday.'],
            ]);
        }

        $toMinutes = static function (?string $hm): ?int {
            if (! $hm) {
                return null;
            }
            [$h, $m] = array_map('intval', explode(':', substr($hm, 0, 5)));

            return $h * 60 + $m;
        };

        $offHalf = $this->halfDays->offHalfFor($employee->id, $day->toDateString());

        if ($offHalf === HalfDayWindowService::BOTH) {
            throw ValidationException::withMessages([
                'work_date' => ['You already have the whole of that day off.'],
            ]);
        }

        [$startTime, $endTime] = $this->halfDays->windowFor($eff, $offHalf);

        $start = $toMinutes($startTime);
        $end = $toMinutes($endTime);
        $chosen = $toMinutes($time);

        if ($start === null || $end === null || $end <= $start) {
            return; // no usable schedule window — nothing to validate against
        }

        if ($chosen <= $start || $chosen >= $end) {
            throw ValidationException::withMessages([
                'early_out_time' => [
                    "Pick a time within your shift ({$startTime}–{$endTime}).",
                ],
            ]);
        }
    }

    private function assertPending(EarlyOutRequest $request, string $action): void
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
     * Pending only, so nothing downstream has to be unwound: an APPROVED early-out request has already
     * changed what the day is worth, and reversing that is an HR act with a paper trail.
     */
    public function cancel(EarlyOutRequest $request, User $actor, string $reason): EarlyOutRequest
    {
        $this->applyCancellation($request, $actor, $reason, 'early-out request');

        $request->load(['employee.user', 'reviewer', 'canceller']);
        $name = $request->employee?->user?->name ?? 'an employee';

        $this->audit->log(
            'cancelled',
            'Attendance',
            "Cancelled {$name}'s early-out request for " . $request->work_date->format('M j') . " — {$reason}",
            $request,
        );

        return $request;
    }
}
