<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Models\Concerns\BelongsToOrg;

use App\Models\Org;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A budgeted amount for one account in one month (period = "YYYY-MM").
 */
class Budget extends Model
{
    use BelongsToOrg;
    protected $table = 'acc_budgets';

    protected $fillable = [
        'org_id',
        'period',
        'account_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
