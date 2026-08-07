<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One debit or credit. Exactly one side carries an amount — storing both columns rather than a signed
 * figure is what every accounting export and every accountant expects to read.
 */
class JournalLine extends Model
{
    protected $table = 'hr_journal_lines';

    protected $fillable = ['journal_entry_id', 'gl_account_id', 'debit', 'credit', 'memo'];

    protected $casts = ['debit' => 'float', 'credit' => 'float'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
