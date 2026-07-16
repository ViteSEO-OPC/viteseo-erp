<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only lifecycle event for an outbound email (RC3). One row per transition — the auditable
 * timeline behind an email's send lifecycle. RC3 records send-lifecycle types
 * (scheduled/rescheduled/cancelled/queued/sending/sent/failed); Post-RC engagement analytics add new
 * `type` values (delivered/opened/clicked/bounced/unsubscribed) with no schema change. Never updated.
 *
 * @property Carbon|null $occurred_at Cast to datetime.
 */
#[Fillable([
    'email_id',
    'type',
    'occurred_at',
    'meta',
])]
class EmailEvent extends Model
{
    protected $table = 'crm_email_events';

    /** No created_at/updated_at columns — `occurred_at` is the single, immutable timestamp. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Email, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
