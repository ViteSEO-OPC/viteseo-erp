<?php

namespace App\Modules\Crm\Models;

use App\Models\User;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Outbound outreach email history. One row per successful send, independent of the
 * transport used (provider column records which one). Persisted by OutreachEmailService.
 *
 * @property Carbon|null $sent_at Cast to datetime; declared so static analysis applies the cast.
 * @property Carbon|null $received_at Cast to datetime; declared so static analysis applies the cast.
 */
#[Fillable([
    'lead_id',
    'user_id',
    'direction',
    'from_email',
    'from_name',
    'to_email',
    'to_name',
    'subject',
    'body',
    'provider',
    'provider_message_id',
    'message_id',
    'in_reply_to',
    'references',
    'thread_id',
    'status',
    'error',
    'sent_at',
    'received_at',
    'raw_headers',
])]
class Email extends Model
{
    protected $table = 'crm_emails';

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'raw_headers' => 'array',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
