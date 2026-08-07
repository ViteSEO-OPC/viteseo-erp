<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payroll run's double-entry, as it hits the books (FIN-043).
 *
 * `draft` is computed and editable; `posted` is on the books and immutable; `reversed` is still on the
 * books but cancelled by a mirror entry that points back at it. Correcting a posted entry means
 * reversing it, never editing it — see the migration for why.
 */
class JournalEntry extends Model
{
    protected $table = 'hr_journal_entries';

    protected $fillable = [
        'payroll_run_id', 'posting_date', 'reference', 'status',
        'posted_at', 'posted_by', 'reversal_of_id', 'note',
    ];

    protected $casts = [
        'posting_date' => 'date',
        'posted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** What this entry reverses, when it is itself a reversal. */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function totalDebit(): float
    {
        return round((float) $this->lines->sum('debit'), 2);
    }

    public function totalCredit(): float
    {
        return round((float) $this->lines->sum('credit'), 2);
    }

    /** An entry that does not balance must never post — see JournalEntryService::assertBalanced(). */
    public function isBalanced(): bool
    {
        return abs($this->totalDebit() - $this->totalCredit()) < 0.01;
    }
}
