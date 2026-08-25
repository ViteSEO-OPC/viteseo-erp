<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayslipRequest;
use App\Modules\Hr\Services\Concerns\PagesQueues;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class PayslipRequestService
{
    use PagesQueues;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * One page of the queue. HR sees everyone's; anybody else sees only their own, and that
     * scope is applied BEFORE the filters so no search can widen it.
     *
     * @param  array<string, mixed>  $filters  search, status, page, per_page
     */
    public function list(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = PayslipRequest::with(['employee.user', 'reviewer', 'payslip.payrollRun']);

        if (! $user->hasAnyRole(['hr_officer', 'super_admin'])) {
            $query->where('employee_id', $user->employee?->id);
        }

        return $this->paginateQueue(
            $query,
            $filters,
            ['reason'],
            priorityStatuses: ['pending'],
        );
    }

    /** @param array<string, mixed> $data */
    public function create(Employee $employee, array $data): PayslipRequest
    {
        $request = PayslipRequest::create([
            'employee_id' => $employee->id,
            'payslip_id' => $data['payslip_id'] ?? null,
            'reason' => $data['reason'],
            'status' => 'pending',
        ])->load('employee.user');

        $this->audit->log('submitted', 'Payroll', 'Signed hard-copy request', $request);

        return $request;
    }

    public function approve(PayslipRequest $request, User $reviewer): PayslipRequest
    {
        $this->assertPending($request, 'approve');

        $request->update([
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $employeeName = $request->employee->user?->name ?? $request->employee->name;
        $this->audit->log(
            'approved',
            'Payroll',
            "Approved signed hard-copy request for {$employeeName}",
            $request,
        );

        return $request->load(['employee.user', 'reviewer', 'payslip.payrollRun']);
    }

    public function reject(PayslipRequest $request, User $reviewer, ?string $reason = null): PayslipRequest
    {
        $this->assertPending($request, 'reject');

        $request->update([
            'status' => 'rejected',
            'review_note' => $reason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $employeeName = $request->employee->user?->name ?? $request->employee->name;
        $this->audit->log(
            'declined',
            'Payroll',
            "Declined signed hard-copy request for {$employeeName}",
            $request,
        );

        return $request->load(['employee.user', 'reviewer', 'payslip.payrollRun']);
    }

    /**
     * Undo the decision — back to pending, reviewer stamps cleared. Safe to reverse:
     * this only gates who may collect a signed hard copy, it never touches pay.
     */
    public function reopen(PayslipRequest $request, ?string $reason = null): PayslipRequest
    {
        $this->assertDecided($request);

        $request->update([
            'status' => 'pending',
            'review_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        $employeeName = $request->employee->user?->name ?? $request->employee->name;
        $this->audit->log(
            'reopened',
            'Payroll',
            "Reopened {$employeeName}'s signed hard-copy request" . ($reason ? " — {$reason}" : ''),
            $request,
        );

        return $request->load(['employee.user', 'reviewer', 'payslip.payrollRun']);
    }

    private function assertPending(PayslipRequest $request, string $action): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["Cannot {$action} a request that is {$request->status}."],
            ]);
        }
    }

    private function assertDecided(PayslipRequest $request): void
    {
        if ($request->status === 'pending') {
            throw ValidationException::withMessages([
                'status' => ['This request is still pending — there is nothing to undo.'],
            ]);
        }
    }
}
