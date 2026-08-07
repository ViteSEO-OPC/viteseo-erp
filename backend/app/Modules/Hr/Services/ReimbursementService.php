<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Reimbursement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
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

    public function __construct(
        private readonly AuditService $audit,
        private readonly PayrollFreezeService $freeze,
    ) {}

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
    /**
     * A claim that looks like one already on file (FIN-032).
     *
     * WHAT IT CATCHES. Nothing stopped the same receipt being filed twice: no unique index, no
     * check on (employee, date, amount, category). Both copies could be approved and both folded
     * into the same payslip, paying the expense twice — and a duplicate is indistinguishable from a
     * genuine second claim after the fact, so it is not recoverable by inspection later.
     *
     * WARNED, NOT BLOCKED, and that is the whole design. Two ₱180 taxi rides on the same day in the
     * same category is an ordinary Tuesday. Refusing it outright would be wrong often enough that
     * people would learn to work around it — by nudging the amount, which destroys the very signal
     * this uses. So a match is surfaced and the submitter confirms.
     *
     * Cancelled and rejected claims are excluded: re-filing something that was turned down is the
     * correct response to being turned down.
     */
    public function nearDuplicateOf(Employee $employee, array $data, ?int $ignoreId = null): ?Reimbursement
    {
        return Reimbursement::where('employee_id', $employee->id)
            ->where('category', $data['category'])
            ->whereDate('expense_date', $data['expense_date'])
            ->whereRaw('ABS(amount - ?) < 0.01', [(float) $data['amount']])
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->latest('id')
            ->first();
    }

    public function create(Employee $employee, array $data, array $receipts = []): Reimbursement
    {
        // FIN-032 — refuse a look-alike unless the submitter says it is genuinely separate.
        if (! ($data['acknowledge_duplicate'] ?? false)) {
            $existing = $this->nearDuplicateOf($employee, $data);

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'A ' . $existing->category . ' claim for ₱' . number_format((float) $existing->amount, 2)
                            . ' on ' . $existing->expense_date->format('M j, Y') . ' is already on file ('
                            . $existing->status . '). Confirm if this is a separate expense.',
                    ],
                ]);
            }
        }

        // Store each receipt on the public disk so it's viewable via /storage.
        $paths = array_values(array_map(
            static fn (UploadedFile $file): string => $file->store('receipts', 'local'),
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

    /**
     * Undo the last mark, one step back: paid → approved, approved/rejected → pending.
     *
     * A `scheduled` claim is refused outright — payroll has folded it into a payslip that
     * hasn't gone out yet, so the run is the thing to change, not the claim. A `paid` claim
     * that payroll settled is refused while that payslip stands (reverse the payslip first);
     * one HR marked paid by hand has no payslip behind it and reverses freely.
     */
    public function reopen(Reimbursement $claim, ?string $reason = null): Reimbursement
    {
        $this->assertStatus($claim, ['approved', 'rejected', 'paid'], 'undo');

        $wasPaid = $claim->status === 'paid';

        if ($wasPaid) {
            $this->freeze->assertPayslipOpen(
                $claim->payroll_run_id,
                $claim->employee_id,
                'undo this payment',
            );
        }

        // Paid steps back to approved (still owed); a decision steps back to undecided.
        $claim->update($wasPaid ? ['status' => 'approved'] : [
            'status' => 'pending',
            'review_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        $claim->load(['employee.user', 'reviewer']);
        $employeeName = $claim->employee->user?->name ?? $claim->employee->name;
        $this->audit->log(
            'reopened',
            'Reimbursement',
            ($wasPaid
                ? "Reversed the payment on {$employeeName}'s {$claim->category} claim"
                : "Reopened {$employeeName}'s {$claim->category} claim")
                . ($reason ? " — {$reason}" : ''),
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
