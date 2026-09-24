<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound;

use App\Modules\Crm\Models\ConversationMessage;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Writes the outbound half of `crm_messages`, and makes a retry find the first attempt instead of
 * creating a second one.
 *
 * THE UNIQUENESS IS PHASE 3A'S, REUSED RATHER THAN REPLACED. `crm_messages` already carries
 * `UNIQUE(org_id, idempotency_key)`, which was built for inbound replay and happens to be exactly
 * the constraint an outbound retry needs — the key is different (client-owned, never a content
 * hash) but the guarantee is the same one. Adding a second outbound-only constraint would mean two
 * rules governing one table with no statement of which wins.
 *
 * FIND-THEN-CREATE-THEN-FIND, deliberately in that order. The first read answers the common case
 * cheaply; the insert is the real arbiter; and the catch handles the narrow window where two
 * requests both read nothing and both tried to write. Trusting the read alone would let a double
 * click create two rows under concurrency, which for outbound means a customer receiving the same
 * message twice.
 *
 * AN ATTEMPT WITHOUT A KEY IS NOT DEDUPLICATED, and that follows from {@see OutboundIdempotency}:
 * a caller that supplied no client request id has said this attempt has no identity, and inventing
 * one from the body would silently collapse two legitimate identical messages into one.
 *
 * NOTHING HERE MARKS A MESSAGE DELIVERED. A row is created `pending` and moves to `sent` only when
 * a provider has confirmed it — which cannot happen in this phase, because no transport exists.
 */
final class OutboundMessagePersister
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /**
     * The durable record of this send attempt: the existing one if this key has been seen, or a
     * fresh `pending` row.
     */
    public function pending(OutboundMessageRequest $request, ?string $idempotencyKey): ConversationMessage
    {
        if ($idempotencyKey !== null) {
            $existing = $this->find($request->orgId(), $idempotencyKey);

            if ($existing !== null) {
                return $existing;
            }
        }

        try {
            return $this->create($request, $idempotencyKey);
        } catch (UniqueConstraintViolationException $violation) {
            // Two attempts with one key raced. The winner's row is the answer; ours was never
            // written. The violation is NOT chained — a QueryException embeds its bindings, and a
            // binding here is the customer's message text.
            unset($violation);

            $existing = $idempotencyKey === null
                ? null
                : $this->find($request->orgId(), $idempotencyKey);

            if ($existing === null) {
                throw new OutboundPersistenceException(
                    'Outbound message could not be persisted and no winning row was found.',
                );
            }

            return $existing;
        }
    }

    /** The provider accepted it. The only path that may set a provider message id. */
    public function markSent(ConversationMessage $message, string $providerMessageId): void
    {
        $message->delivery_status = self::SENT;
        $message->external_message_id = $providerMessageId;
        $message->delivered_at = now();
        $message->failure_code = null;
        $message->save();
    }

    /** A code, never a reason: provider text can quote the customer's message back at us. */
    public function markFailed(ConversationMessage $message, OutboundFailureCode $code): void
    {
        $message->delivery_status = self::FAILED;
        $message->failure_code = $code->value;
        $message->save();
    }

    /**
     * The attempt happened and we never learned whether it was delivered.
     *
     * COVERS EVERY AMBIGUOUS OUTCOME, not just a timeout. A POST that timed out and a POST that
     * came back 5xx are the same problem: Meta may have delivered the message, or may never have
     * seen it, and nothing in the protocol tells us which. `sent` would claim a delivery we cannot
     * prove; `failed` would deny one that may have happened. Both are lies, and both are actionable
     * by somebody, which is worse than an honest unknown.
     *
     * So the row STAYS `pending` — it is, genuinely, unresolved — and carries the SPECIFIC code, so
     * the operator can still tell "Meta returned an error" from "we never heard back". A fresh
     * pending row has `failure_code` null, so `pending` + an unresolved code is unambiguous and
     * needs no new column.
     *
     * NO MIGRATION. `delivery_status` keeps its existing vocabulary and `failure_code` is an
     * existing nullable varchar(32). Adding a fourth status would have meant a migration, a
     * backfill decision and every existing reader learning a new value, for no information this
     * pair does not already carry.
     */
    public function markUnresolved(ConversationMessage $message, OutboundFailureCode $code): void
    {
        if (! $code->leavesDeliveryUnresolved()) {
            // A caller that reaches here with a deterministic code has confused "the send failed"
            // with "we do not know whether the send happened". Refusing is better than silently
            // parking a resolved failure in `pending`, where nothing will ever revisit it.
            throw new OutboundPersistenceException(sprintf(
                'Refusing to park a resolved outcome [%s] as unresolved.',
                $code->value,
            ));
        }

        $message->delivery_status = self::PENDING;
        $message->failure_code = $code->value;
        // Explicit rather than merely absent: nothing about this outcome is a delivery.
        $message->delivered_at = null;
        $message->save();
    }

    /**
     * The code a previous ambiguous attempt left on this row, or null if it is not in that state.
     *
     * The guard that makes the rule binding: the service asks this BEFORE calling a transport, so a
     * replay of the same client request id refuses instead of posting a second time. Returning the
     * CODE rather than a boolean lets the refusal name the original reason, which is the difference
     * between an operator checking Meta's status page and an operator checking the thread.
     */
    public function unresolvedCode(ConversationMessage $message): ?OutboundFailureCode
    {
        if ($message->delivery_status !== self::PENDING) {
            return null;
        }

        $code = OutboundFailureCode::tryFrom((string) ($message->failure_code ?? ''));

        return $code !== null && $code->leavesDeliveryUnresolved() ? $code : null;
    }

    /** Whether a previous attempt on this row left delivery unknown. */
    public function isUnresolved(ConversationMessage $message): bool
    {
        return $this->unresolvedCode($message) !== null;
    }

    private function find(int $orgId, string $idempotencyKey): ?ConversationMessage
    {
        return ConversationMessage::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function create(OutboundMessageRequest $request, ?string $idempotencyKey): ConversationMessage
    {
        $message = new ConversationMessage([
            'provider' => $request->provider(),
            'channel' => $request->channel(),
            'direction' => 'outbound',
            'message_type' => $request->messageType,
            'body' => $request->body,
            'delivery_status' => self::PENDING,
            'occurred_at' => now(),
        ]);

        // Outside mass assignment on purpose, matching the inbound rule: parenting and the
        // idempotency key are the engine's decisions, never a caller's.
        $message->conversation_id = (int) $request->conversation->id;
        $message->org_id = $request->orgId();
        $message->idempotency_key = $idempotencyKey ?? $this->unkeyed($request);
        $message->save();

        return $message;
    }

    /**
     * A key for an attempt that declined to have one.
     *
     * `idempotency_key` is NOT NULL and uniquely indexed, so a row still needs a value. A random
     * one is the honest choice: it cannot collide, and it cannot make two unrelated attempts look
     * like the same send. It is deliberately not derived from anything about the message.
     */
    private function unkeyed(OutboundMessageRequest $request): string
    {
        return hash('sha256', 'outbound:unkeyed:'.$request->orgId().':'.bin2hex(random_bytes(16)));
    }
}
