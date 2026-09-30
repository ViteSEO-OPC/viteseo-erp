<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound;

use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

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
     * Phase 3H.5A — how long a claimed-but-unrecorded attempt counts as "still sending".
     *
     * The provider call is bounded far below this: GraphMetaSender allows 5 s to connect and 10 s
     * in total, and PHP-FPM's max_execution_time is 30 s. A row still `pending` with no outcome two
     * minutes after it was handed to the provider is therefore not slow — its request died after
     * the provider may have accepted the message and before the result was written. From that point
     * it is reported as unresolved, never re-sent, and never guessed as sent or failed.
     *
     * The same window applies to an unclaimed `pending` row by its creation time: a row nobody
     * claimed within two minutes is either from before claims existed or from a request that died
     * mid-way, and neither is safe to send on a replay.
     */
    public const STRANDED_AFTER_SECONDS = 120;

    /** Manual resolution outcomes (Phase 3H.5A). */
    public const OUTCOME_SENT = 'sent';

    public const OUTCOME_NOT_SENT = 'not_sent';

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

    /**
     * The provider accepted it. The only path that may set a provider message id.
     *
     * Like every provider-result write, this is a compare-and-set (see {@see self::recordOutcome()})
     * and returns false, changing nothing, if the row stopped waiting for this result first.
     */
    public function markSent(ConversationMessage $message, string $providerMessageId): bool
    {
        return $this->recordOutcome($message, [
            'delivery_status' => self::SENT,
            'external_message_id' => $providerMessageId,
            'delivered_at' => now(),
            'failure_code' => null,
        ], null);
    }

    /** A code, never a reason: provider text can quote the customer's message back at us. */
    public function markFailed(ConversationMessage $message, OutboundFailureCode $code): bool
    {
        return $this->recordOutcome($message, [
            'delivery_status' => self::FAILED,
            'failure_code' => $code->value,
            'delivered_at' => null,
        ], $code);
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
    public function markUnresolved(ConversationMessage $message, OutboundFailureCode $code): bool
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

        return $this->recordOutcome($message, [
            'delivery_status' => self::PENDING,
            'failure_code' => $code->value,
            // Explicit rather than merely absent: nothing about this outcome is a delivery.
            'delivered_at' => null,
        ], $code);
    }

    /**
     * Phase 3H.5A — write a provider result only while the row is STILL WAITING FOR THAT RESULT.
     *
     * THE RACE THIS CLOSES. A provider call can outlive the stranded window: once a claimed row has
     * had no outcome for {@see self::STRANDED_AFTER_SECONDS}, an operator may resolve it by hand.
     * The original request, still holding its now-stale model, used to `save()` its late result on
     * top of that verdict — and because Eloquent writes only the attributes it thinks changed, the
     * row could end up self-contradictory (`sent` carrying `operator_confirmed_not_sent`). The
     * operator looked at the thread; a result that arrives after them loses.
     *
     * ONE CONDITIONAL UPDATE, NO LOCK. The precondition is in the WHERE clause, so the database
     * decides; nothing is held across the provider call, which has already returned by now.
     *
     * BOUND TO THIS ATTEMPT'S CLAIM, not merely to "pending with no outcome". A failed row — an
     * operator's `not_sent` included — may be claimed again by a replay, which puts it back to
     * `pending` with no outcome under a NEW `send_attempted_at`. Matching the claim time this model
     * was loaded with stops the first attempt's late result being written onto the second attempt's
     * row. Two claims of one row cannot share a second: a re-claim needs the row to have been
     * resolved first, and resolution needs it stranded for two minutes. A row that was never
     * claimed (NULL) matches only while it is still unclaimed.
     *
     * `send_attempted_at` is never written here: it records when the provider was called.
     *
     * ZERO ROWS IS NOT AN ERROR. The row is left exactly as it is, nothing is thrown or retried, and
     * the provider is not called again; a sanitized warning records that a result was discarded.
     *
     * @param  array<string, mixed>  $changes
     */
    private function recordOutcome(ConversationMessage $message, array $changes, ?OutboundFailureCode $code): bool
    {
        $claimedAt = $message->getRawOriginal('send_attempted_at');

        $query = ConversationMessage::query()
            ->withoutGlobalScopes()
            ->whereKey($message->getKey())
            ->where('org_id', $message->org_id)
            ->where('direction', 'outbound')
            ->where('delivery_status', self::PENDING)
            ->whereNull('failure_code');

        $claimedAt === null
            ? $query->whereNull('send_attempted_at')
            : $query->where('send_attempted_at', $claimedAt);

        if ($query->update($changes) === 1) {
            // The UPDATE bypassed the model; bring it level with what was written.
            $message->refresh();

            return true;
        }

        $current = ConversationMessage::query()
            ->withoutGlobalScopes()
            ->whereKey($message->getKey())
            ->first(['id', 'delivery_status', 'failure_code']);

        // WARNING, because production runs at LOG_LEVEL=warning. Identifiers and codes only: no
        // body, no customer identifier, and not even the provider message id — only whether one
        // was being recorded.
        Log::warning('crm.outbound.late_outcome_ignored', [
            'message_id' => (int) $message->getKey(),
            'conversation_id' => (int) $message->conversation_id,
            'org_id' => (int) $message->org_id,
            'attempted_status' => $changes['delivery_status'],
            'attempted_code' => $code?->value,
            'provider_message_id_present' => ($changes['external_message_id'] ?? null) !== null,
            'current_status' => $current?->delivery_status,
            'current_code' => $current?->failure_code,
        ]);

        return false;
    }

    /**
     * The code a previous ambiguous attempt left on this row, or null if it is not in that state.
     *
     * The guard that makes the rule binding: the service asks this BEFORE calling a transport, so a
     * replay of the same client request id refuses instead of posting a second time. Returning the
     * CODE rather than a boolean lets the refusal name the original reason, which is the difference
     * between an operator checking Meta's status page and an operator checking the thread.
     */
    public function unresolvedCode(ConversationMessage $message, ?CarbonInterface $now = null): ?OutboundFailureCode
    {
        if ($message->delivery_status !== self::PENDING) {
            return null;
        }

        if ($message->failure_code === null) {
            /*
             * Phase 3H.5A. A pending row with no outcome is either on its way (fine) or STRANDED —
             * handed to the provider by a request that died before recording the result. The second
             * is exactly as ambiguous as a timeout, so it gets the same code and the same refusal.
             * It is not WRITTEN as delivery_unknown: nothing observed a timeout, and the row stays
             * honest about what we saw. The code is derived on read.
             */
            return $this->isStranded($message, $now) ? OutboundFailureCode::DeliveryUnknown : null;
        }

        $code = OutboundFailureCode::tryFrom($message->failure_code);

        return $code !== null && $code->leavesDeliveryUnresolved() ? $code : null;
    }

    /**
     * Phase 3H.5A — a pending outbound row whose outcome was never recorded and is now too old to
     * still be in flight. See {@see self::STRANDED_AFTER_SECONDS}.
     *
     * Measured from `send_attempted_at` when the row was claimed, otherwise from `created_at`: an
     * old row that was never claimed predates claims or belongs to a request that died, and neither
     * is safe to treat as "not yet sent".
     */
    public function isStranded(ConversationMessage $message, ?CarbonInterface $now = null): bool
    {
        if ($message->direction !== 'outbound'
            || $message->delivery_status !== self::PENDING
            || $message->failure_code !== null) {
            return false;
        }

        $since = $message->send_attempted_at ?? $message->created_at;

        return $since !== null
            && $since->lte(($now ?? now())->copy()->subSeconds(self::STRANDED_AFTER_SECONDS));
    }

    /**
     * Phase 3H.5A — atomically claim this row for ONE provider call. True only for the caller that
     * won the claim; only that caller may call the adapter.
     *
     * THE HOLE THIS CLOSES. `pending()` hands back the existing row for a repeated client request
     * id, and before this a row that was `pending` with no outcome went straight to the provider
     * again — whether a first request was still in flight or had died after Meta accepted the
     * message. Either way the customer could receive it twice.
     *
     * A COMPARE-AND-SET, NOT A LOCK. One conditional UPDATE, evaluated by the database: a second
     * caller's UPDATE matches nothing once the first has stamped `send_attempted_at`. No row lock
     * and no transaction is held across the provider's HTTP call, which can take seconds.
     *
     * Claimable:
     *  - a `failed` row — failure is definitive, so a deliberate re-attempt on the same id stays
     *    allowed, exactly as before; or
     *  - a `pending` row with no outcome, never claimed, and created inside the stranded window —
     *    i.e. a row this request (or a request that died before reaching the provider) just made.
     *
     * Never claimable: `sent`, a `pending` row carrying an unresolved code, a row already claimed,
     * or an old unclaimed `pending` row. Each of those may already have reached the customer.
     *
     * NOTE ON SQLITE. The feature tests run on SQLite, which cannot reproduce MariaDB's concurrent
     * transaction interleaving. They prove the state transition — that a claimed row cannot be
     * claimed again — and a re-entrant test proves an in-flight attempt blocks a second one; they
     * cannot prove InnoDB's behaviour under true parallel connections. A single UPDATE with this
     * WHERE clause is atomic per row on InnoDB, which is what the design relies on.
     */
    public function claim(ConversationMessage $message, ?CarbonInterface $now = null): bool
    {
        $now = ($now ?? now())->copy();
        $freshestUnclaimed = $now->copy()->subSeconds(self::STRANDED_AFTER_SECONDS);

        $affected = ConversationMessage::query()
            ->withoutGlobalScopes()
            ->whereKey($message->getKey())
            ->where('org_id', $message->org_id)
            ->where('direction', 'outbound')
            ->where(function (Builder $claimable) use ($freshestUnclaimed): void {
                $claimable->where('delivery_status', self::FAILED)
                    ->orWhere(function (Builder $fresh) use ($freshestUnclaimed): void {
                        $fresh->where('delivery_status', self::PENDING)
                            ->whereNull('failure_code')
                            ->whereNull('send_attempted_at')
                            ->where('created_at', '>', $freshestUnclaimed);
                    });
            })
            ->update([
                'send_attempted_at' => $now,
                'delivery_status' => self::PENDING,
                'failure_code' => null,
                'delivered_at' => null,
            ]);

        if ($affected !== 1) {
            return false;
        }

        // The UPDATE bypassed the model; bring it level before the outcome is written onto it.
        $message->refresh();

        return true;
    }

    /**
     * Phase 3H.5A — the ONE definition of "unresolved", as a query constraint.
     *
     * Shared by the conversation summary and the manual resolution compare-and-set, and equivalent
     * to {@see self::unresolvedCode()} returning non-null, so the list an operator sees, the rows
     * they may resolve and the rows the send path refuses to replay can never disagree.
     */
    public function whereUnresolved(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $cutoff = ($now ?? now())->copy()->subSeconds(self::STRANDED_AFTER_SECONDS);

        return $query
            ->where('direction', 'outbound')
            ->where('delivery_status', self::PENDING)
            ->where(function (Builder $unresolved) use ($cutoff): void {
                $unresolved->whereIn('failure_code', self::unresolvedCodeValues())
                    ->orWhere(function (Builder $stranded) use ($cutoff): void {
                        $stranded->whereNull('failure_code')
                            ->where(function (Builder $old) use ($cutoff): void {
                                $old->where('send_attempted_at', '<=', $cutoff)
                                    ->orWhere(function (Builder $unclaimed) use ($cutoff): void {
                                        $unclaimed->whereNull('send_attempted_at')
                                            ->where('created_at', '<=', $cutoff);
                                    });
                            });
                    });
            });
    }

    /**
     * Phase 3H.5A — the unresolved outbound messages of one conversation, oldest first.
     *
     * Read from the database only, so it survives a reload, another device and another operator.
     * Bounded: a thread with more unresolved sends than this has a problem no list will fix.
     *
     * @return Collection<int, ConversationMessage>
     */
    public function unresolvedInConversation(Conversation $conversation, ?CarbonInterface $now = null): Collection
    {
        $query = ConversationMessage::query()
            ->withoutGlobalScopes()
            ->where('org_id', $conversation->org_id)
            ->where('conversation_id', $conversation->id);

        return $this->whereUnresolved($query, $now)
            ->orderBy('id')
            ->limit(50)
            ->get();
    }

    /**
     * Phase 3H.5A — an operator's verdict on an unresolved message, applied only if it is STILL
     * unresolved. Returns false, and changes nothing, if anything moved it first.
     *
     * COMPARE-AND-SET on the same predicate as {@see self::whereUnresolved()}: a second operator,
     * a late result from the original request, or a double click all lose cleanly instead of
     * overwriting a real outcome.
     *
     * `sent` NEVER INVENTS A PROVIDER ID. `external_message_id` is left untouched (null), so the API
     * keeps reporting `providerMessageIdPresent: false` and the row stays distinguishable from a
     * provider-confirmed send. `delivered_at` stays null too: when it was delivered is exactly what
     * nobody knows. The audit Activity records who confirmed it and when.
     *
     * `not_sent` becomes `failed` with {@see OutboundFailureCode::OperatorConfirmedNotSent}.
     */
    public function resolveManually(
        ConversationMessage $message,
        string $outcome,
        int $orgId,
        ?CarbonInterface $now = null,
    ): bool {
        $changes = match ($outcome) {
            self::OUTCOME_SENT => [
                'delivery_status' => self::SENT,
                'failure_code' => null,
                'delivered_at' => null,
            ],
            self::OUTCOME_NOT_SENT => [
                'delivery_status' => self::FAILED,
                'failure_code' => OutboundFailureCode::OperatorConfirmedNotSent->value,
                'delivered_at' => null,
            ],
            default => throw new OutboundPersistenceException(sprintf('Unknown resolution outcome [%s].', $outcome)),
        };

        $query = ConversationMessage::query()
            ->withoutGlobalScopes()
            ->whereKey($message->getKey())
            ->where('org_id', $orgId)
            ->where('conversation_id', $message->conversation_id);

        return $this->whereUnresolved($query, $now)->update($changes) === 1;
    }

    /** @return list<string> */
    private static function unresolvedCodeValues(): array
    {
        return array_values(array_map(
            static fn (OutboundFailureCode $code): string => $code->value,
            array_filter(
                OutboundFailureCode::cases(),
                static fn (OutboundFailureCode $code): bool => $code->leavesDeliveryUnresolved(),
            ),
        ));
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
