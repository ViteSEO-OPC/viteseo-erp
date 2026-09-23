<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Modules\Crm\Database\Factories\ConversationMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn inside a {@see Conversation}.
 *
 * Named ConversationMessage, not Message: `Message` would collide with the inbound-email DTO
 * vocabulary (Services\Mail\Inbound\InboundMessage) and with any future notification/system
 * message concept. Same reasoning that named PlannerEvent rather than Event.
 *
 * THREE columns are deliberately kept OUT of mass assignment, and are set explicitly by the code
 * that owns them:
 *
 *   - `conversation_id` — parenting is the ingest service's decision, never client input (the same
 *     rule Call keeps for its ownership FKs).
 *   - `idempotency_key` — derived by ConversationIdempotency. A caller that could set it could
 *     forge or defeat replay protection, which is the one invariant this table exists to hold.
 *   - `processed_at`  — owned by InboundConversationProcessor; it is the marker that says work has
 *     already run, so nothing outside the processor may declare it done.
 */
#[Fillable([
    // Phase 3F.2 delivery state. Fillable because the outbound persister builds a whole row in one
    // create() and these are part of that row; they are meaningless on inbound (see the migration)
    // and no inbound code path sets them.
    'delivery_status',
    'failure_code',
    'delivered_at',
    'provider',
    'channel',
    'external_message_id',
    'external_event_id',
    'direction',
    'sender_external_id',
    'recipient_external_id',
    'message_type',
    'body',
    'payload',
    'occurred_at',
    'received_at',
])]
class ConversationMessage extends Model
{
    use BelongsToOrg;

    /** @use HasFactory<ConversationMessageFactory> */
    use HasFactory;

    protected $table = 'crm_messages';

    protected static function newFactory(): ConversationMessageFactory
    {
        return ConversationMessageFactory::new();
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function isInbound(): bool
    {
        return $this->direction === 'inbound';
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
