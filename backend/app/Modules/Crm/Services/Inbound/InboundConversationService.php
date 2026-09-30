<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound;

use App\Modules\Crm\Events\InboundConversationMessageReceived;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\Lead;
use App\Support\CurrentOrg;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Lands one normalized inbound conversation turn in the CRM — the chat counterpart to
 * InboundEmailService::ingest, and modelled on it deliberately: idempotency first, then thread
 * resolution, then matching, then one transaction, then side effects.
 *
 *   normalized message → idempotency check → conversation resolve/create → lead match
 *                      → persist → Activity → post-processing → domain event (after commit)
 *
 * AT-LEAST-ONCE IS ASSUMED. Providers retry, and a retry must cost nothing: the first thing this
 * does is look for the message's idempotency key, and a hit returns immediately with
 * {@see InboundIngestResult::replayed()}. Nothing after that point runs — no Activity, no event,
 * no post-processing, no recency bump, no second matching attempt. Re-deciding the lead on a
 * replay would let a duplicate attach a lead the first ingest deliberately left unmatched; that is
 * reconciliation, a separate designed process, not ingest.
 *
 * TWO KINDS OF UNIQUE VIOLATION, KEPT APART. This distinction is the correctness core of the
 * class, and conflating them is how a concurrent pair of DIFFERENT messages used to lose one:
 *
 *   - CONVERSATION identity — handled where it belongs, inside {@see ConversationResolver}, which
 *     re-reads the winner's thread under a lock and carries on. It never reaches this class, so a
 *     lost thread-creation race is NOT a replay and the current message is still persisted.
 *   - MESSAGE identity — reaches the catch below, and only there does "already on file" mean the
 *     event is a duplicate.
 *
 * TENANCY. The org is read once from CurrentOrg and passed down explicitly. With no org context
 * this THROWS rather than guessing — an inbound event whose tenant is unknown must fail loudly,
 * because the quiet alternative is writing one company's conversation into another's CRM.
 *
 * LOGGING BOUNDARY. `payload` and message text are persisted but NEVER logged, and a failed write
 * is re-thrown as {@see InboundConversationPersistenceException} precisely because a raw
 * QueryException carries the bindings — body and payload — into any log that records it.
 *
 * This service never creates a lead, never assigns ownership, and never writes leads.status.
 */
final class InboundConversationService
{
    public function __construct(
        private readonly ConversationIdempotency $idempotency,
        private readonly ConversationResolver $conversations,
        private readonly ConversationLeadMatcher $matcher,
        private readonly InboundConversationProcessor $processor,
    ) {}

    public function ingest(InboundConversationMessage $message): InboundIngestResult
    {
        $orgId = CurrentOrg::id();

        if ($orgId === null) {
            throw new RuntimeException(
                'Inbound conversation ingest requires an org context; refusing to guess a tenant.',
            );
        }

        $key = $this->idempotency->keyFor($message);

        $replay = $this->findExisting($orgId, $message, $key);
        if ($replay !== null) {
            return $this->asReplay($orgId, $message, $key, $replay);
        }

        try {
            [$stored, $conversation, $strategy] = DB::transaction(
                fn (): array => $this->persist($orgId, $message, $key),
            );
        } catch (UniqueConstraintViolationException $violation) {
            // MESSAGE identity only (see the class docblock): two deliveries of the same event
            // raced into the write and the database arbitrated. The loser re-reads the winner's
            // row and reports a replay — its own Activity, processed_at and queued event all
            // rolled back with the transaction, so the side effects still happened exactly once.
            $existing = $this->findExisting($orgId, $message, $key);

            if ($existing === null) {
                throw $this->persistenceFailure($violation, $orgId, $message, $key);
            }

            return $this->asReplay($orgId, $message, $key, $existing);
        } catch (QueryException $failure) {
            throw $this->persistenceFailure($failure, $orgId, $message, $key);
        }

        Log::info('crm.inbound.conversation.ingested', $this->diagnostics($orgId, $message, $key, [
            'conversation_id' => $conversation->id,
            'message_id' => $stored->id,
            'lead_match_strategy' => $strategy,
            'lead_matched' => $conversation->lead_id !== null,
            'result' => 'created',
        ]));

        return InboundIngestResult::created($stored, $conversation, $strategy);
    }

    /**
     * Both identity guards, in the order the schema states them: the always-present derived key
     * first, then the provider's own message id when it issued one.
     */
    private function findExisting(int $orgId, InboundConversationMessage $message, string $key): ?ConversationMessage
    {
        $byKey = ConversationMessage::query()
            ->where('org_id', $orgId)
            ->where('idempotency_key', $key)
            ->first();

        if ($byKey !== null) {
            return $byKey;
        }

        $externalMessageId = $this->trimmedOrNull($message->externalMessageId);

        if ($externalMessageId === null) {
            return null;
        }

        return ConversationMessage::query()
            ->where('org_id', $orgId)
            ->where('provider', $message->provider)
            ->where('channel', $message->channel)
            ->where('external_message_id', $externalMessageId)
            ->first();
    }

    private function asReplay(
        int $orgId,
        InboundConversationMessage $message,
        string $key,
        ConversationMessage $existing,
    ): InboundIngestResult {
        Log::info('crm.inbound.conversation.replayed', $this->diagnostics($orgId, $message, $key, [
            'conversation_id' => $existing->conversation_id,
            'message_id' => $existing->id,
            'result' => 'replay',
        ]));

        $conversation = $existing->conversation()->firstOrFail();

        return InboundIngestResult::replayed($existing, $conversation);
    }

    /**
     * @return array{0: ConversationMessage, 1: Conversation, 2: string}
     */
    private function persist(int $orgId, InboundConversationMessage $message, string $key): array
    {
        $occurredAt = $this->parseOccurredAt($message->occurredAt);
        $receivedAt = Carbon::now();
        $happenedAt = $occurredAt ?? $receivedAt;

        // Returns the thread held FOR UPDATE for the rest of this transaction, whether it already
        // existed, was created here, or was created by a racing request. Everything below therefore
        // reads and writes a conversation nobody else can be changing.
        $conversation = $this->conversations->resolve($orgId, $message, $happenedAt);

        $match = $this->matcher->match($orgId, $message, $conversation);

        $stored = $this->storeMessage($conversation, $message, $key, $occurredAt, $receivedAt);

        $this->conversations->advance($conversation, $message, $match['lead_id'], $happenedAt);

        $this->logActivity($conversation, $stored, $match['strategy']);

        $this->processor->process($stored, $conversation);

        if ($stored->isInbound()) {
            // Dispatched here, but the event implements ShouldDispatchAfterCommit, so the framework
            // holds it until the OUTERMOST transaction commits and drops it on rollback. Registering
            // it inside the transaction is what ties "the event fired" to "the row exists".
            InboundConversationMessageReceived::dispatch($stored, $conversation);
        }

        return [$stored->refresh(), $conversation->refresh(), $match['strategy']];
    }

    /**
     * `conversation_id` and `idempotency_key` are set directly, not filled: parenting and replay
     * protection are this service's to decide, and both columns are outside $fillable so no caller
     * can supply them.
     */
    private function storeMessage(
        Conversation $conversation,
        InboundConversationMessage $message,
        string $key,
        ?Carbon $occurredAt,
        Carbon $receivedAt,
    ): ConversationMessage {
        $stored = new ConversationMessage([
            'provider' => $message->provider,
            'channel' => $message->channel,
            'external_message_id' => $this->trimmedOrNull($message->externalMessageId),
            'external_event_id' => $this->trimmedOrNull($message->externalEventId),
            'direction' => $message->direction,
            'sender_external_id' => $this->trimmedOrNull($message->senderExternalId),
            'recipient_external_id' => $this->trimmedOrNull($message->recipientExternalId),
            'message_type' => $message->messageType,
            'body' => $message->text,
            'payload' => $message->rawPayload === [] ? null : $message->rawPayload,
            'occurred_at' => $occurredAt,
            'received_at' => $receivedAt,
        ]);

        $stored->conversation_id = $conversation->id;
        $stored->idempotency_key = $key;
        $stored->save();

        return $stored;
    }

    /**
     * One Activity per newly stored inbound turn.
     *
     * Attached to the LEAD when the thread is associated, so it appears on the lead's timeline
     * beside emails and calls; attached to the CONVERSATION otherwise, so an unmatched thread
     * still has an audit trail instead of none. `causer_id` is null — inbound has no CRM user
     * behind it, and renders as "System", the same convention as email.received.
     *
     * The properties are identifiers only: no payload, no message body.
     */
    private function logActivity(Conversation $conversation, ConversationMessage $stored, string $strategy): void
    {
        if (! $stored->isInbound()) {
            return;
        }

        $properties = [
            'provider' => $stored->provider,
            'channel' => $stored->channel,
            'conversation_id' => $conversation->id,
            'message_id' => $stored->id,
            'message_type' => $stored->message_type,
            'lead_match_strategy' => $strategy,
        ];

        $subject = $this->activitySubject($conversation);

        $subject->activities()->create([
            'event' => 'conversation.message_received',
            'description' => 'Inbound '.$stored->channel.' message received',
            'properties' => $properties,
            'causer_id' => null,
        ]);
    }

    /**
     * Where this turn's Activity is filed: the lead when the thread names a resolvable one, the
     * conversation otherwise.
     *
     * THE FALLBACK IS NOT DEFENSIVE PROGRAMMING; IT CLOSES A REAL WAY TO KILL A THREAD. This used
     * to be `$conversation->lead()->firstOrFail()`. `Lead` uses SoftDeletes, so the relation query
     * carries `deleted_at IS NULL` — and archiving a lead does NOT clear `crm_conversations.lead_id`
     * (nothing in the archive path touches conversations, and the FK's ON DELETE SET NULL fires
     * only on a hard delete, which the module never performs). So a linked-then-archived lead made
     * that call throw ModelNotFoundException, INSIDE the ingest transaction, which `ingest()` does
     * not catch — it handles UniqueConstraintViolationException and QueryException and nothing
     * else. The message rolled back, the provider retried, and every retry failed identically:
     * the thread went permanently deaf to inbound.
     *
     * It also spread. `ConversationLeadMatcher::byContactIdentity()` reads other conversations'
     * `lead_id` without excluding trashed leads, so a brand-new thread from the same contact would
     * auto-attach to the archived lead and die on its very first message.
     *
     * Nothing triggers it in production today — zero conversations are linked — but Phase 3H.3A
     * ships manual linking, and six leads are already archived. The fix had to land in the same
     * slice.
     *
     * WHAT THIS DELIBERATELY DOES NOT DO: it does not clear `lead_id`, does not re-run matching,
     * does not restore the lead and does not touch ConversationLeadMatcher. The association is
     * still a true statement about the thread; only the Activity's SUBJECT falls back, so the
     * audit trail exists somewhere rather than nowhere. If the lead is restored, later turns file
     * against it again with no further action.
     */
    private function activitySubject(Conversation $conversation): Conversation|Lead
    {
        if ($conversation->lead_id === null) {
            return $conversation;
        }

        $lead = $conversation->lead()->first();

        if ($lead !== null) {
            return $lead;
        }

        // Rare and worth seeing: it means a linked lead was archived out from under a live thread.
        // Identifiers only, consistent with every other log line in this class.
        Log::warning('crm.inbound.conversation.lead_unresolved', [
            'org_id' => (int) $conversation->org_id,
            'conversation_id' => (int) $conversation->id,
            'lead_id' => (int) $conversation->lead_id,
        ]);

        return $conversation;
    }

    /**
     * Restate a database failure without its query. See
     * {@see InboundConversationPersistenceException} for why the original is not chained.
     */
    private function persistenceFailure(
        QueryException $exception,
        int $orgId,
        InboundConversationMessage $message,
        string $key,
    ): InboundConversationPersistenceException {
        $diagnostics = $this->diagnostics($orgId, $message, $key, [
            // Five characters by the SQL standard, but it is the driver's string and not ours.
            'sqlstate' => SafeIdentifier::for((string) $exception->getCode(), SafeIdentifier::CODE),
            'result' => 'persistence_failed',
        ]);

        Log::error('crm.inbound.conversation.persistence_failed', $diagnostics);

        return InboundConversationPersistenceException::from($exception, $diagnostics);
    }

    private function parseOccurredAt(?string $occurredAt): ?Carbon
    {
        $value = $this->trimmedOrNull($occurredAt);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            // A malformed provider timestamp is not a reason to drop the message; our own
            // received_at still orders it, and the raw value survives in the payload.
            return null;
        }
    }

    /**
     * Safe diagnostics only: identifiers a support engineer can act on, and nothing a person wrote.
     *
     * THE IDENTIFIERS ARE SANITIZED HERE, AND ONLY HERE. Four of them originate outside this
     * system — provider and channel come from whichever adapter normalized the event, and the two
     * external ids come straight from the provider's payload. In structured log context a newline
     * does not mangle a field, it forges a RECORD: a fabricated line that a dashboard cannot tell
     * from one this application emitted. {@see SafeIdentifier} strips control and format
     * characters, repairs invalid UTF-8 and bounds each value to its column width.
     *
     * This array feeds the three log calls AND the message of
     * {@see InboundConversationPersistenceException}, so one boundary covers both.
     *
     * NOTHING FUNCTIONAL READS THIS. Sanitization is lossy — two different providers could
     * sanitize to one string — so it must never touch what identifies a conversation, derives the
     * idempotency key or gets persisted. Those paths use `$message` directly and are untouched:
     * this method's output is written to a log and to an exception message, and nowhere else.
     *
     * `idempotency_key` is left alone deliberately: it is this system's own SHA-256 hex digest,
     * bounded and character-safe by construction, and passing it through would be redundant.
     *
     * @param  array<string, scalar|null>  $extra
     * @return array<string, scalar|null>
     */
    private function diagnostics(int $orgId, InboundConversationMessage $message, string $key, array $extra): array
    {
        return [
            'org_id' => $orgId,
            'provider' => SafeIdentifier::for($message->provider, SafeIdentifier::PROVIDER),
            'channel' => SafeIdentifier::for($message->channel, SafeIdentifier::CHANNEL),
            'external_conversation_id' => SafeIdentifier::for($message->externalConversationId, SafeIdentifier::EXTERNAL_ID),
            'external_message_id' => SafeIdentifier::for($message->externalMessageId, SafeIdentifier::EXTERNAL_ID),
            'idempotency_key' => $key,
            ...$extra,
        ];
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
