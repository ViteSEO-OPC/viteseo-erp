<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Reimbursement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReimbursementService
{
    /** Allowed expense categories (also enforced in the Form Request). */
    public const CATEGORIES = [
        'travel',
        'meals',
        'supplies',
        'equipment',
        'software',
        'training',
        'other',
    ];

    public function __construct(private readonly AuditService $audit) {}

    /** HR sees all claims; everyone else sees only their own. */
    public function list(User $user): Collection
    {
        $query = Reimbursement::query()
            ->with(['employee.user', 'reviewer'])
            ->latest();

        if (! $user->hasAnyRole(['hr_officer', 'super_admin'])) {
            $query->where('employee_id', $user->employee?->id);
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $receipts
     */
    public function create(Employee $employee, array $data, array $receipts = []): Reimbursement
    {
        // Store each receipt on the public disk so it's viewable via /storage.
        $paths = array_values(array_map(
            static fn (UploadedFile $file): string => $file->store('receipts', 'public'),
            $receipts,
        ));

        $claim = Reimbursement::create([
            'employee_id' => $employee->id,
            'category' => $data['category'],
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'description' => $data['description'],
            'receipt_paths' => $paths ?: null,
            'status' => 'pending',
        ])->load(['employee.user']);

        $this->audit->log(
            'submitted',
            'Reimbursement',
            "{$claim->category} claim (₱" . number_format((float) $claim->amount, 0) . ')',
            $claim,
        );

        return $claim;
    }

    public function approve(Reimbursement $claim, User $reviewer): Reimbursement
    {
        $this->assertStatus($claim, ['pending'], 'approve');

        $claim = $this->transition($claim, 'approved', $reviewer);

        $employeeName = $claim->employee->user?->name ?? $claim->employee->name;
        $this->audit->log(
            'approved',
            'Reimbursement',
            "Approved {$employeeName}'s {$claim->category} claim",
            $claim,
        );

        return $claim;
    }

    public function reject(Reimbursement $claim, User $reviewer, ?string $reason = null): Reimbursement
    {
        $this->assertStatus($claim, ['pending'], 'reject');

        $claim = $this->transition($claim, 'rejected', $reviewer, $reason);

        $employeeName = $claim->employee->user?->name ?? $claim->employee->name;
        $this->audit->log(
            'declined',
            'Reimbursement',
            "Declined {$employeeName}'s {$claim->category} claim",
            $claim,
        );

        return $claim;
    }

    public function markPaid(Reimbursement $claim, User $reviewer): Reimbursement
    {
        // pending → approved → (scheduled) → paid. `scheduled` means payroll has folded the
        // claim into a payslip that hasn't been disbursed yet (Slice 12); HR can still settle it
        // by hand — e.g. paid in cash before the payslip goes out.
        $this->assertStatus($claim, ['approved', 'scheduled'], 'mark as paid');

        $claim = $this->transition($claim, 'paid', $reviewer);

        $employeeName = $claim->employee->user?->name ?? $claim->employee->name;
        $this->audit->log(
            'updated',
            'Reimbursement',
            "Marked {$employeeName}'s claim paid",
            $claim,
        );

        return $claim;
    }

    private function transition(Reimbursement $claim, string $status, User $reviewer, ?string $reason = null): Reimbursement
    {
        $claim->update([
            'status' => $status,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $reason,
        ]);

        return $claim->load(['employee.user', 'reviewer']);
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function assertStatus(Reimbursement $claim, array $allowed, string $action): void
    {
        if (! in_array($claim->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot {$action} a claim that is {$claim->status}."],
            ]);
        }
    }
}
