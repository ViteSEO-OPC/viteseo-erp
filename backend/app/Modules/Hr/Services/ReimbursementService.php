<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Reimbursement;
use App\Modules\Hr\Services\Concerns\CancelsOwnRequest;
use App\Modules\Hr\Services\Concerns\PagesQueues;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ReimbursementService
{
    use CancelsOwnRequest;
    use PagesQueues;

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
    /**
     * The six summary figures the finance screen shows above the queue — counts and peso totals
     * per status.
     *
     * Item 5. These were computed in the browser by filtering the full list, which worked only
     * while the full list was what arrived. Once the queue serves one page, the same code would
     * describe THAT PAGE while looking exactly as authoritative: "₱12,400 pending" meaning
     * "pending among the ten rows on screen". A wrong number nobody can tell is wrong.
     *
     * One grouped query rather than six, because the six are the same scan.
     *
     * @return array<string, array{count:int, amount:float}>
     */
    public function summary(): array
    {
        $rows = Reimbursement::query()
            ->selectRaw('status, count(*) as claims, coalesce(sum(amount), 0) as total')
            ->groupBy('status')
            ->toBase()
            ->get();

        $summary = [];
        foreach ($rows as $row) {
            $summary[(string) $row->status] = [
                'count' => (int) $row->claims,
                'amount' => (float) $row->total,
            ];
        }

        return $summary;
    }

    /**
     * One page of the claims queue. HR sees everyone's; anybody else sees only their own, and
     * that scope is applied BEFORE the filters so no search can widen it.
     *
     * @param  array<string, mixed>  $filters  search, status, page, per_page
     */
    public function list(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = Reimbursement::query()
            ->with(['employee.user', 'reviewer', 'canceller']);

        if (! $user->hasAnyRole(['hr_officer', 'super_admin'])) {
            $query->where('employee_id', $user->employee?->id);
        }

        return $this->paginateQueue(
            $query,
            $filters,
            ['category', 'description'],
            priorityStatuses: ['pending'],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $receipts
     */
    /** A claim that looks like one already on file. */
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
        // Refuse a look-alike unless the submitter says it is genuinely separate.
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
        // claim into a payslip that hasn't been disbursed yet; HR can still settle it
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

    /** Undo the last mark, one step back: paid → approved, approved/rejected → pending. */
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

    /** The employee withdraws a claim they filed by mistake, while HR has yet to decide it. */
    public function cancel(Reimbursement $claim, User $actor, string $reason): Reimbursement
    {
        $this->applyCancellation($claim, $actor, $reason, 'claim');

        $this->audit->log(
            'cancelled',
            'Reimbursements',
            "Cancelled a {$claim->category} claim — {$reason}",
            $claim,
        );

        return $claim->load(['employee.user', 'reviewer', 'canceller']);
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
