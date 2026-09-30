<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound;

use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3H.5A — an operator's verdict on an outbound message whose delivery nobody could confirm.
 *
 * WHY A HUMAN. The Send API gives no way to ask whether a timed-out message arrived, and nothing
 * on our side reconciles it later. The operator can: Meta's own inbox shows exactly what the
 * customer received. This records that verdict — "it was sent" or "it was not sent" — durably and
 * with an audit trail, so the unresolved state stops following the conversation forever.
 *
 * WHAT THIS NEVER DOES: call the provider, re-send anything, or invent a provider message id. It
 * writes one column change on one row, and only while that row is still unresolved.
 *
 * ONE SHORT TRANSACTION, NO EXTERNAL CALL INSIDE IT. The row is re-read under a lock to record its
 * true prior state for the audit, then changed by a compare-and-set that re-checks the unresolved
 * condition. Either the verdict and its Activity both land, or neither does. SQLite (the test
 * database) ignores the lock; the compare-and-set alone still guarantees a stale verdict changes
 * nothing.
 */
final class OutboundDeliveryResolver
{
    public const EVENT = 'conversation.message_resolved';

    public function __construct(private readonly OutboundMessagePersister $persister) {}

    /**
     * @param  OutboundMessagePersister::OUTCOME_*  $outcome
     * @return bool true when this call resolved the message; false when it was no longer unresolved
     */
    public function resolve(
        Conversation $conversation,
        ConversationMessage $message,
        string $outcome,
        User $actor,
        int $actingOrgId,
    ): bool {
        return DB::transaction(function () use ($conversation, $message, $outcome, $actor, $actingOrgId): bool {
            $current = ConversationMessage::query()
                ->withoutGlobalScopes()
                ->whereKey($message->getKey())
                ->where('org_id', $actingOrgId)
                ->where('conversation_id', $conversation->id)
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                return false;
            }

            // Captured BEFORE the change, for the audit: what the operator was looking at.
            $previousCode = $current->failure_code;
            $wasStranded = $this->persister->isStranded($current);

            if (! $this->persister->resolveManually($current, $outcome, $actingOrgId)) {
                return false;
            }

            $this->recordActivity($conversation, $current, $outcome, $previousCode, $wasStranded, $actor, $actingOrgId);

            $message->refresh();

            return true;
        });
    }

    /**
     * The durable record of who decided, on the CONVERSATION's timeline — the message belongs to the
     * thread, and the thread may have no lead at all.
     *
     * Identifiers and the verdict only: no message body, no contact or provider identifiers, no
     * credential material.
     */
    private function recordActivity(
        Conversation $conversation,
        ConversationMessage $message,
        string $outcome,
        ?string $previousCode,
        bool $wasStranded,
        User $actor,
        int $actingOrgId,
    ): void {
        /** @var Activity $activity */
        $activity = $conversation->activities()->make([
            'event' => self::EVENT,
            'description' => sprintf(
                '%s confirmed a %s reply as %s',
                $actor->name,
                $conversation->channel,
                $outcome === OutboundMessagePersister::OUTCOME_SENT ? 'sent' : 'not sent',
            ),
            'properties' => [
                'conversation_id' => (int) $conversation->id,
                'message_id' => (int) $message->id,
                'outcome' => $outcome,
                'previous_failure_code' => $previousCode,
                'was_stranded' => $wasStranded,
                'provider' => $conversation->provider,
                'channel' => $conversation->channel,
            ],
            'causer_id' => $actor->id,
        ]);

        $activity->org_id = $actingOrgId;
        $activity->save();
    }
}
