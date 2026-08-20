<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\OfficeExpense;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class OfficeExpenseService
{
    public const CATEGORIES = ['utilities', 'supplies', 'equipment', 'maintenance', 'events', 'rent', 'other'];

    /** The review states an expense can be in. `recorded` is where every expense starts. */
    public const STATUSES = ['recorded', 'approved', 'rejected'];

    public function __construct(private readonly AuditService $audit) {}

    public function list(): Collection
    {
        return OfficeExpense::with(['recorder', 'reviewer'])->latest('expense_date')->get();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?UploadedFile $receipt = null): OfficeExpense
    {
        $expense = OfficeExpense::create([
            'category' => $data['category'],
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'description' => $data['description'],
            'vendor' => $data['vendor'] ?? null,
            'receipt_path' => $receipt?->store('office-expenses', 'local'),
            'status' => 'recorded',
            'recorded_by' => Auth::id(),
        ]);

        $this->audit->log('created', 'Office Expenses', $this->describe($expense, 'Recorded'), $expense);

        // An expense can be filed already-decided (HR entering a past expense that was approved
        // at the time). It is still WRITTEN as `recorded` first, then transitioned, so the
        // approval gets its own reviewer, timestamp and audit line rather than appearing from
        // nowhere as a column default.
        $this->applyRequestedStatus($expense, $data);

        return $expense->load(['recorder', 'reviewer']);
    }

    /** @param array<string, mixed> $data */
    public function update(OfficeExpense $expense, array $data, ?UploadedFile $receipt = null): OfficeExpense
    {
        $expense->update([
            'category' => $data['category'],
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'description' => $data['description'],
            'vendor' => $data['vendor'] ?? null,
            'receipt_path' => $receipt ? $receipt->store('office-expenses', 'local') : $expense->receipt_path,
        ]);

        $this->audit->log('updated', 'Office Expenses', $this->describe($expense, 'Updated'), $expense);

        $this->applyRequestedStatus($expense, $data);

        return $expense->load(['recorder', 'reviewer']);
    }

    /**
     * Move an expense to the status the form asked for, if it asked for one.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyRequestedStatus(OfficeExpense $expense, array $data): void
    {
        $wanted = $data['status'] ?? null;

        if ($wanted === null || $wanted === $expense->status) {
            return;
        }

        $note = $data['review_note'] ?? null;

        // Reviewing requires a clean slate, so a decided expense is reopened first. That is the
        // same two steps HR would take by hand, and each one records its own audit line.
        if ($expense->status !== 'recorded') {
            $this->reopen($expense, 'Changed while editing the expense');
        }

        if ($wanted === 'recorded') {
            return;
        }

        $reviewer = Auth::user();

        if (! $reviewer instanceof User) {
            return;
        }

        $wanted === 'approved'
            ? $this->approve($expense, $reviewer)
            : $this->reject($expense, $reviewer, $note);
    }

    public function approve(OfficeExpense $expense, User $reviewer): OfficeExpense
    {
        return $this->transition($expense, 'approved', $reviewer);
    }

    public function reject(OfficeExpense $expense, User $reviewer, ?string $reason = null): OfficeExpense
    {
        return $this->transition($expense, 'rejected', $reviewer, $reason);
    }

    /**
     * Undo the review — back to recorded, awaiting a decision again. Office expenses
     * are company spend, never employee pay, so nothing downstream needs unwinding.
     */
    public function reopen(OfficeExpense $expense, ?string $reason = null): OfficeExpense
    {
        if ($expense->status === 'recorded') {
            throw ValidationException::withMessages([
                'status' => ['This expense has not been reviewed yet — there is nothing to undo.'],
            ]);
        }

        $expense->update([
            'status' => 'recorded',
            'review_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        $this->audit->log(
            'reopened',
            'Office Expenses',
            $this->describe($expense, 'Reopened') . ($reason ? " — {$reason}" : ''),
            $expense,
        );

        return $expense->load(['recorder', 'reviewer']);
    }

    public function delete(OfficeExpense $expense): void
    {
        $expense->delete();
    }

    private function transition(OfficeExpense $expense, string $status, User $reviewer, ?string $reason = null): OfficeExpense
    {
        if ($expense->status !== 'recorded') {
            throw ValidationException::withMessages([
                'status' => ["Cannot review an expense that is {$expense->status}."],
            ]);
        }

        $expense->update([
            'status' => $status,
            'review_note' => $reason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $this->audit->log(
            $status === 'approved' ? 'approved' : 'declined',
            'Office Expenses',
            $this->describe($expense, $status === 'approved' ? 'Approved' : 'Declined'),
            $expense,
        );

        return $expense->load(['recorder', 'reviewer']);
    }

    /** Audit-trail line for an expense, e.g. "Recorded a supplies expense — ₱1,200.00". */
    private function describe(OfficeExpense $expense, string $verb): string
    {
        return "{$verb} a {$expense->category} expense — ₱" . number_format((float) $expense->amount, 2);
    }
}
