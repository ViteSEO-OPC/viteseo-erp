<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An org's subscription to the platform (billing source of truth). Platform-
 * level — NOT tenant-scoped, so super-admin billing sees every org's plan.
 */
class Subscription extends Model
{
    public const PENDING = 'pending';     // never activated — no access yet
    public const TRIALING = 'trialing';   // free trial, within the trial period
    public const ACTIVE = 'active';       // paid, within the current period
    public const SUSPENDED = 'suspended'; // lapsed — access paused until renewal
    public const CANCELLED = 'cancelled'; // ended by the customer/provider

    /** Free-trial length granted at signup. */
    public const TRIAL_DAYS = 14;

    /** Statuses that grant product access while the period is still open. */
    public const ACCESS_STATUSES = [self::ACTIVE, self::TRIALING];

    protected $table = 'subscriptions';

    protected $fillable = ['org_id', 'plan', 'monthly_price', 'seat_limit', 'status', 'current_period_end'];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'seat_limit' => 'integer',
        'current_period_end' => 'datetime',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    /** Active/trialing AND still within the period — the gate for product access. */
    public function isCurrentlyActive(): bool
    {
        return in_array($this->status, self::ACCESS_STATUSES, true)
            && $this->current_period_end !== null
            && $this->current_period_end->isFuture();
    }

    /** True while on a trial (vs a paid period) — for the "trial ends in X" banner. */
    public function isOnTrial(): bool
    {
        return $this->status === self::TRIALING;
    }
}
