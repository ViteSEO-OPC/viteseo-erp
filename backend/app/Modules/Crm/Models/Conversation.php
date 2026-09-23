<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Modules\Crm\Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A conversation thread with one contact on one provider channel (Messenger, Instagram DM,
 * WhatsApp, web chat, Lead Ads).
 *
 * `lead_id` is deliberately nullable and deliberately STICKY: once a thread is associated with a
 * lead, later messages never re-open the question. An unmatched thread is a legitimate resting
 * state — the chat equivalent of an unqualified prospect — and a human decides what it is.
 *
 * `status` here is the CONVERSATION's lifecycle only. It shares no vocabulary and no machinery
 * with `leads.status`, which this subsystem never writes.
 */
#[Fillable([
    'provider',
    'channel',
    'provider_account_id',
    'external_conversation_id',
    'external_contact_id',
    'status',
    'started_at',
    'last_message_at',
    'metadata',
])]
class Conversation extends Model
{
    use BelongsToOrg;

    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected $table = 'crm_conversations';

    /**
     * Set explicitly: the factory lives under App\Modules\Crm\, so Laravel's convention-based
     * resolver cannot infer the model and guesses App\Conversation.
     */
    protected static function newFactory(): ConversationFactory
    {
        return ConversationFactory::new();
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_message_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The connected resource this thread arrived on — which Page, which Instagram account.
     *
     * Recorded once at ingest and never rewritten, because it is a fact about where the thread
     * lives rather than about its latest message. Null on the conversations that predate the
     * column; those still resolve a sender through the Phase 3F.1 inference path.
     *
     * @return BelongsTo<ProviderAccount, $this>
     */
    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class, 'provider_account_id');
    }

    /** Thread order follows the PROVIDER's clock, falling back to ours when it gave none. */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'conversation_id')
            ->orderByRaw('COALESCE(occurred_at, received_at, created_at) ASC');
    }

    /**
     * Activities logged against the thread itself — used while no lead is matched, so the trail
     * still exists and is not silently dropped on the floor.
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }
}
