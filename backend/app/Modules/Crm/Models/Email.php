<?php

namespace App\Modules\Crm\Models;

use App\Models\User;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Outbound outreach email history. One row per successful send, independent of the
 * transport used (provider column records which one). Persisted by OutreachEmailService.
 * RC3 adds Send-Later scheduling on the SAME row (status scheduled → queued → sending → sent).
 *
 * @property Carbon|null $sent_at Cast to datetime; declared so static analysis applies the cast.
 * @property Carbon|null $received_at Cast to datetime; declared so static analysis applies the cast.
 * @property Carbon|null $scheduled_at Cast to datetime; Send-Later dispatch time.
 * @property Carbon|null $queued_at Cast to datetime; declared so static analysis applies the cast.
 * @property Carbon|null $failed_at Cast to datetime; declared so static analysis applies the cast.
 * @property Carbon|null $cancelled_at Cast to datetime; declared so static analysis applies the cast.
 */
#[Fillable([
    'lead_id',
    'planner_event_id',
    'user_id',
    'direction',
    'from_email',
    'from_name',
    'to_email',
    'to_name',
    'subject',
    'body',
    // RC3 Outreach Automation Pack — business purpose of the send (e.g. proposal, introduction,
    // thank_you). Nullable; set only by the automation send path. Drives the `proposal.sent` event.
    'purpose',
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
    'scheduled_at',
    'queued_at',
    'failed_at',
    'cancelled_at',
    'attempts',
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
            'scheduled_at' => 'datetime',
            'queued_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    /**
     * The Planner "Email Task" event that owns this email's schedule (RC3). Null for standalone
     * Send-Later emails. When set, the linked event's `starts_at` is the authoritative schedule.
     *
     * @return BelongsTo<PlannerEvent, $this>
     */
    public function plannerEvent(): BelongsTo
    {
        return $this->belongsTo(PlannerEvent::class);
    }

    /**
     * Append-only send-lifecycle timeline (RC3).
     *
     * @return HasMany<EmailEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class);
    }
}
