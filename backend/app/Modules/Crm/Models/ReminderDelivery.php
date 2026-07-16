<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * RC3 Phase 3 — an append-only record that a Planner-event reminder was delivered on a given channel.
 * Owned by the delivery subsystem (NOT Planner). Its existence is the idempotency guard; the relation to
 * PlannerEvent lives here so the Planner model stays immutable.
 *
 * @property Carbon|null $delivered_at Cast to datetime.
 */
#[Fillable([
    'planner_event_id',
    'channel',
    'delivered_at',
    'meta',
])]
class ReminderDelivery extends Model
{
    protected $table = 'crm_reminder_deliveries';

    /** No created_at/updated_at — `delivered_at` is the single, immutable timestamp. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<PlannerEvent, $this> */
    public function plannerEvent(): BelongsTo
    {
        return $this->belongsTo(PlannerEvent::class);
    }
}
