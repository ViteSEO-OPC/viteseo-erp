<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Models\Concerns\BelongsToOrg;

use App\Models\Org;
use App\Models\User;
use App\Modules\Accounting\Enums\JournalSourceType;
use App\Modules\Accounting\Enums\JournalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A double-entry journal entry: one transaction, made of balanced debit/credit
 * lines. Once posted it is immutable — corrections are made with a reversing entry.
 */
class JournalEntry extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'acc_journal_entries';

    protected $fillable = [
        'org_id',
        'entry_date',
        'reference',
        'memo',
        'source_type',
        'source_id',
        'status',
        'posted_by',
        'posted_at',
        'reversed_at',
        'reversal_entry_id',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'status' => JournalStatus::class,
        'source_type' => JournalSourceType::class,
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isPosted(): bool
    {
        return $this->status === JournalStatus::Posted;
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
