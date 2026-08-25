<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\OfficeExpense;
use App\Modules\Hr\Services\Concerns\PagesQueues;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class OfficeExpenseService
{
    use PagesQueues;

    public const CATEGORIES = ['utilities', 'supplies', 'equipment', 'maintenance', 'events', 'rent', 'other'];

    /** The review states an expense can be in. `recorded` is where every expense starts. */
    public const STATUSES = ['recorded', 'approved', 'rejected'];

    /**
     * The orderings the ledger's sort control offers, as a closed list.
     *
     * A closed list rather than a column name off the query string: `?sort=` reaching orderBy()
     * unchecked is how a filter control becomes a way to probe columns the screen never shows.
     * Anything unrecognised falls through to the default ordering.
     */
    private const SORTS = [
        'amount_desc' => [['amount', 'desc']],
        'amount_asc' => [['amount', 'asc']],
        'date_desc' => [['expense_date', 'desc']],
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * One page of the office-expense ledger, newest spend first with anything still awaiting a
     * decision on top. Searchable by what was bought and who from — there is no employee on an
     * expense, only the person who recorded it, so the filer branch is off.
     *
     * @param  array<string, mixed>  $filters  search, status, page, per_page
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return $this->paginateQueue(
            $this->window(OfficeExpense::with(['recorder', 'reviewer']), $filters),
            $filters,
            ['category', 'description', 'vendor'],
            searchEmployee: false,
            priorityStatuses: ['recorded'],
            recencyColumn: 'expense_date',
            sort: self::SORTS[$filters['sort'] ?? ''] ?? null,
        );
    }

    /**
     * The spend totals above the ledger: the whole window, and the two categories it went to
     * most. Counted by the DATABASE over every matching row.
     *
     * Item 5. These were added up in the browser from whatever had been downloaded — which,
     * once the ledger serves one page, is ten rows. "Total spend ₱4,300" meaning "across these
     * ten" is a wrong number that looks exactly like a right one.
     *
     * Takes the same $filters as list() so the cards describe the ledger BELOW them. A summary
     * that ignored the period picker would contradict the table on the same screen.
     *
     * @param  array<string, mixed>  $filters
     * @return array{total: float, by_category: array<string, float>}
     */
    public function summary(array $filters = []): array
    {
        $rows = $this->window(OfficeExpense::query(), $filters)
            ->selectRaw('category, coalesce(sum(amount), 0) as spend')
            ->groupBy('category')
            ->toBase()
            ->get();

        $byCategory = [];
        $total = 0.0;
        foreach ($rows as $row) {
            $spend = (float) $row->spend;
            $byCategory[(string) $row->category] = $spend;
            $total += $spend;
        }

        arsort($byCategory);

        return ['total' => $total, 'by_category' => $byCategory];
    }

    /**
     * The date window the period picker sets, applied to the QUERY. Filtering by date in the
     * browser could only ever narrow the page it was handed.
     *
     * @param  array<string, mixed>  $filters
     */
    private function window(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('expense_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('expense_date', '<=', $to));
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
