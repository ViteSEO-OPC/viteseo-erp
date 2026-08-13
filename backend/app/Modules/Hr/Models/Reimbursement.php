<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reimbursement extends Model
{
    use SoftDeletes;

    protected $table = 'hr_reimbursements';

    protected $fillable = [
        'employee_id',
        'category',
        'amount',
        'expense_date',
        'description',
        'receipt_path',
        'receipt_paths',
        'status',
        'payroll_run_id',
        'reviewed_at',
        'reviewed_by',
        'review_note',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'reviewed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'receipt_paths' => 'array',
    ];

    /**
     * All stored receipt paths for this claim, merging the legacy single-path
     * column with the multi-file array. Older rows only have `receipt_path`.
     *
     * @return array<int, string>
     */
    public function allReceiptPaths(): array
    {
        $paths = $this->receipt_paths ?? [];

        if ($this->receipt_path) {
            array_unshift($paths, $this->receipt_path);
        }

        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM.
     *
     * Without it the default scope resolves this to null the moment somebody leaves, and every
     * Resource that reads a name through it throws, taking the whole list endpoint down with it —
     * one 500 for the entire page because one row belonged to a leaver. Their history does not
     * stop being theirs when they go, and a record that cannot say whose it is is worse than one
     * that names someone who has left.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Who withdrew this request, when it was cancelled rather than decided. */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
