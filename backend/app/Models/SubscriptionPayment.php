<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded subscription payment — the audit + undo record for billing. Not
 * tenant-scoped (platform-level, like Subscription); org_id is the provider org.
 */
class SubscriptionPayment extends Model
{
    public const RECORDED = 'recorded';
    public const REVERSED = 'reversed';

    protected $table = 'acc_subscription_payments';

    protected $fillable = [
        'org_id',
        'subscription_id',
        'customer_org_id',
        'months',
        'monthly_amount',
        'total_amount',
        'period_before',
        'period_after',
        'status_before',
        'invoice_id',
        'revenue_schedule_id',
        'status',
        'recorded_by',
        'reversed_at',
        'reversed_by',
    ];

    protected $casts = [
        'months' => 'integer',
        'monthly_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'period_before' => 'datetime',
        'period_after' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
