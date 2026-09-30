<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound;

use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use Illuminate\Support\Carbon;

/**
 * What happens AFTER a new inbound conversation turn is stored — the chat counterpart to
 * InboundReplyProcessor.
 *
 * Deliberately almost empty in Phase 3A. The email processor completes Planner follow-ups,
 * advances the outreach status and refreshes the Lead Score; none of that is repeated here,
 * because deciding what a chat message means for the sales workflow is a separate, reviewable
 * decision. What this class establishes now is the SEAM and the idempotency marker, so that
 * later behaviour has exactly one place to live and cannot accidentally run twice.
 *
 * This class OWNS `processed_at`. The column is not mass-assignable precisely so that nothing
 * else can declare a message handled; the assignment below is the only writer.
 *
 * Rules it will keep whatever is added later:
 *   - never writes leads.status (a manual BD decision, same as the email path)
 *   - never assigns lead ownership
 *   - never creates a lead
 *   - runs once per message, guarded by processed_at
 */
final class InboundConversationProcessor
{
    /**
     * @return bool whether processing ran (false = already processed, nothing repeated)
     */
    public function process(ConversationMessage $message, Conversation $conversation): bool
    {
        if ($message->processed_at !== null) {
            return false;
        }

        // Set directly rather than through update(): `processed_at` is intentionally outside
        // $fillable, so a fill-based write would be silently discarded. saveQuietly keeps this
        // bookkeeping from waking observers or stacking an extra Activity on the thread.
        $message->processed_at = Carbon::now();
        $message->saveQuietly();

        return true;
    }
}
