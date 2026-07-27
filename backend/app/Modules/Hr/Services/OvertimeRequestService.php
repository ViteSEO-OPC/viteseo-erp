<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OvertimeRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Overtime requests — an employee asks for N overtime HOURS on a date. HR approves; payroll pays each
 * APPROVED one at the configured OT rate (see PayrollService, OT-3). Mirrors EarlyOutRequestService,
 * opposite sign (positive pay, not a deduction). No shift-window check — OT can fall on a workday OR
 * a rest day; the hours bound lives in StoreOvertimeRequestRequest.
 */
class OvertimeRequestService
{
    public function __construct(private readonly AuditService $audit) {}

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
        $req = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'work_date' => $data['work_date'],
            'hours' => $data['hours'],
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

    private function assertPending(OvertimeRequest $request, string $action): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["Cannot {$action} a request that is {$request->status}."],
            ]);
        }
    }
}
