<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;

/**
 * A deferred-revenue recognition schedule: monthly_amount recognized once a
 * month for `months` months, moving the unearned portion from a liability into
 * revenue. Org-scoped (lives in the biller/provider's books).
 */
class RevenueSchedule extends Model
{
    use BelongsToOrg;

    public const ACTIVE = 'active';
    public const COMPLETED = 'completed';
    public const REVERSED = 'reversed';

    protected $table = 'acc_revenue_schedules';

    protected $fillable = [
        'org_id',
        'description',
        'monthly_amount',
        'months',
        'recognized_count',
        'next_recognition_date',
        'unearned_account_id',
        'revenue_account_id',
        'status',
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'monthly_amount' => 'decimal:2',
        'months' => 'integer',
        'recognized_count' => 'integer',
        'next_recognition_date' => 'date',
    ];

    /** Months still to recognize. */
    public function remaining(): int
    {
        return max(0, $this->months - $this->recognized_count);
    }
}
