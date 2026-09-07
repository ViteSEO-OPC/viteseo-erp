<?php

namespace App\Modules\Accounting\Models;

use App\Models\Concerns\BelongsToOrg;

use App\Models\Org;
use App\Modules\Accounting\Enums\AccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single line in the chart of accounts (e.g. "5000 Salaries Expense").
 * Belongs to one org (tenant) and optionally nests under a parent account.
 */
class Account extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'acc_accounts';

    protected $fillable = [
        'org_id',
        'code',
        'name',
        'type',
        'parent_id',
        'is_active',
        'is_bank',
    ];

    protected $casts = [
        'type' => AccountType::class,
        'is_active' => 'boolean',
        'is_bank' => 'boolean',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
