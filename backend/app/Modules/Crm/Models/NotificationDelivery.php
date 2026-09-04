<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Multi-Channel Internal Notifications — an append-only record that an INTERNAL notification for an
 * inbound reply was delivered on a given channel. Owned by the delivery subsystem, mirroring
 * {@see ReminderDelivery}: its existence IS the idempotency guard (unique `email_id` + `channel`), and
 * the relation to Email lives here so the Email model stays untouched.
 *
 * @property Carbon|null $delivered_at Cast to datetime.
 */
#[Fillable([
    'email_id',
    'channel',
    'delivered_at',
    'meta',
])]
class NotificationDelivery extends Model
{
    use BelongsToOrg;
    protected $table = 'crm_notification_deliveries';

    /** No created_at/updated_at — `delivered_at` is the single, immutable timestamp. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Email, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
