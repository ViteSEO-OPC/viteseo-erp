<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound;

use App\Modules\Crm\Models\Conversation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Owns the ATOMICITY of one conversation row: finding or creating it under contention, and moving
 * its state forward without losing a concurrent update.
 *
 * Extracted from InboundConversationService for one reason only — both concurrency guarantees are
 * about the same row and must agree with each other. Splitting them across the ingest flow is how
 * the original code ended up assuming that any unique violation meant "replay".
 *
 * WHY A LOCKING READ AND NOT Builder::createOrFirst(). The framework's helper recovers from a lost
 * create race with `useWritePdo()->where(...)->first()` (Eloquent Builder::createOrFirst) — a plain,
 * non-locking read. Ingest runs inside an open transaction, and under InnoDB's default REPEATABLE
 * READ a non-locking read is served from the snapshot taken at this transaction's first read, which
 * was taken BEFORE the winner committed. The row would be invisible and the helper would rethrow.
 * A locking read (`SELECT … FOR UPDATE`) always reads the latest committed version and blocks until
 * the winner commits, which is exactly the semantics this needs.
 *
 * WHY lockForUpdate AND NOT A CONDITIONAL UPDATE for {@see advance()}. A conditional UPDATE is the
 * cleaner tool when one column moves on its own. Here three decisions must be made from ONE
 * consistent view of the row: recency, the earliest-known start, and — critically — whether the
 * thread is still unassociated. The lead decision is made by the matcher BEFORE the write, using
 * this row's `lead_id`; with a conditional UPDATE that read would still be unsynchronized, so the
 * matcher could decide against stale state and only the final write would be guarded. Holding the
 * row for the rest of the transaction makes the whole read-decide-write sequence single-threaded
 * for this conversation.
 *
 * NOTE ON SQLITE. `lockForUpdate()` compiles to nothing on SQLite, so the test suite exercises the
 * recovery LOGIC deterministically but proves nothing about InnoDB's locking semantics.
 */
final class ConversationResolver
{
    /**
     * One retry is enough and the bound is deliberate: attempt 1 loses the create race, attempt 2
     * reads the winner's row under a lock. A second loss would mean the winner's row vanished
     * between the violation and the locking read, which a retry loop cannot fix — it would spin.
     */
    private const MAX_ATTEMPTS = 2;

    public function resolve(int $orgId, InboundConversationMessage $message, Carbon $happenedAt): Conversation
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $existing = $this->lockedLookup($orgId, $message);

            if ($existing !== null) {
                return $existing;
            }

            try {
                return $this->create($message, $happenedAt);
            } catch (UniqueConstraintViolationException $violation) {
                // Someone else created this exact thread between our lookup and our insert. This is
                // a CONVERSATION-identity collision, NOT a message replay: our own message has not
                // been written yet and still must be. Loop once to read the winner under a lock.
                if ($attempt === self::MAX_ATTEMPTS) {
                    // The violation is NOT chained: QueryException embeds its bindings in the
                    // message, and a chained cause is printed by the renderer. The identifiers
                    // below are the safe half of that information.
                    throw new RuntimeException(sprintf(
                        'Conversation identity collision unresolved after %d attempts [org=%d provider=%s channel=%s external_conversation_id=%s]',
                        self::MAX_ATTEMPTS,
                        $orgId,
                        $message->provider,
                        $message->channel,
                        $message->externalConversationId,
                    ));
                }

                unset($violation);
            }
        }

        // Unreachable: the loop either returns or throws.
        throw new RuntimeException('Conversation resolution exhausted its attempts without a result.');
    }

    /**
     * Move the thread forward on the row this transaction holds.
     *
     * Every rule here is monotonic, so applying it twice, or out of order, cannot corrupt the row:
     *   - last_message_at only ever moves to a LATER instant
     *   - started_at only ever moves to an EARLIER instant
     *   - lead_id only ever transitions NULL → matched, never matched → something else
     *   - external_contact_id is only backfilled when the thread had none
     */
    public function advance(
        Conversation $conversation,
        InboundConversationMessage $message,
        ?int $leadId,
        Carbon $happenedAt,
    ): void {
        $changes = [];

        $lastMessageAt = $conversation->last_message_at;
        if ($lastMessageAt === null || $happenedAt->greaterThan($lastMessageAt)) {
            $changes['last_message_at'] = $happenedAt;
        }

        $startedAt = $conversation->started_at;
        if ($startedAt === null || $happenedAt->lessThan($startedAt)) {
            $changes['started_at'] = $happenedAt;
        }

        // An existing association is never replaced — not by a later message, not by a better
        // match, not by a concurrent one. Re-associating a thread is a human/reconciliation
        // decision, and doing it here would silently move one prospect's conversation to another.
        if ($conversation->lead_id === null && $leadId !== null) {
            $changes['lead_id'] = $leadId;
        }

        $contactId = $this->trimmedOrNull($message->senderExternalId);
        if ($conversation->external_contact_id === null && $contactId !== null) {
            $changes['external_contact_id'] = $contactId;
        }

        if ($changes === []) {
            return;
        }

        // Set directly rather than through fill(): `lead_id` is intentionally not mass-assignable,
        // because association is this service's decision and never client input.
        foreach ($changes as $column => $value) {
            $conversation->{$column} = $value;
        }

        $conversation->save();
    }

    /**
     * SELECT … FOR UPDATE on the thread identity. Returns the row held for the remainder of the
     * transaction, so the caller's read-decide-write sequence cannot interleave with another
     * ingest of the same conversation.
     */
    private function lockedLookup(int $orgId, InboundConversationMessage $message): ?Conversation
    {
        return Conversation::query()
            ->where('org_id', $orgId)
            ->where('provider', $message->provider)
            ->where('channel', $message->channel)
            ->where('external_conversation_id', $message->externalConversationId)
            ->lockForUpdate()
            ->first();
    }

    private function create(InboundConversationMessage $message, Carbon $happenedAt): Conversation
    {
        return Conversation::create([
            'provider' => $message->provider,
            'channel' => $message->channel,
            'external_conversation_id' => $message->externalConversationId,
            'external_contact_id' => $this->trimmedOrNull($message->senderExternalId),
            'status' => 'open',
            'started_at' => $happenedAt,
            'last_message_at' => null,
        ]);
    }

    private function trimmedOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
