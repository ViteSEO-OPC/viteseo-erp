<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Half-day requests — a standalone, always-unpaid half-day off (no leave credits).
 * HR approves; payroll deducts 0.5 day for each APPROVED one (see
 * AttendanceService::unpaidDaysFor). Mirrors the leave approval flow.
 */
class HalfDayRequestService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PayrollFreezeService $freeze,
        private readonly RetroFlagService $retro,
    ) {}

    /** HR sees all; an employee sees only their own. */
    public function list(User $user): Collection
    {
        $query = HalfDayRequest::with(['employee.user', 'reviewer'])
            ->orderByDesc('work_date')
            ->latest();

        if (! $user->hasAnyRole(['hr_officer', 'super_admin'])) {
            $query->where('employee_id', $user->employee?->id);
        }

        return $query->get();
    }

    /** @param array<string, mixed> $data */
    public function create(Employee $employee, array $data): HalfDayRequest
    {
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

        $request->load(['employee.user', 'reviewer']);
        $employeeName = $request->employee->user?->name ?? $request->employee->name;
        $this->audit->log(
            'approved',
            'Leave',
            "Approved {$employeeName}'s half-day request",
            $request,
        );

        // FIN-009 — a half-day shortens a paid day, so approving one for a cutoff payroll has
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
}
