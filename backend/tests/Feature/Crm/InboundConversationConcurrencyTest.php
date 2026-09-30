<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Events\InboundConversationMessageReceived;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Inbound\ConversationIdempotency;
use App\Modules\Crm\Services\Inbound\ConversationLeadMatcher;
use App\Modules\Crm\Services\Inbound\InboundConversationMessage;
use App\Modules\Crm\Services\Inbound\InboundConversationPersistenceException;
use App\Modules\Crm\Services\Inbound\InboundConversationService;
use App\Support\CurrentOrg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * The recovery paths the pre-commit review found broken.
 *
 * HONEST LIMIT OF THESE TESTS. They run on SQLite, where `lockForUpdate()` compiles to nothing and
 * there is no usable second connection. They therefore prove the RECOVERY LOGIC — that a lost
 * conversation-creation race is not mistaken for a replay, that the current message still lands on
 * the winner's thread, that an existing message identity resolves to a replay, that an
 * unexplainable violation fails loudly instead of silently, and that the timestamp rules are
 * monotonic. They prove NOTHING about InnoDB locking semantics under real concurrency, and one
 * branch (a duplicate caught by the unique index rather than by the pre-check) is not reachable
 * here at all — see the note on {@see test_an_existing_message_identity_returns_a_replay}.
 *
 * The conversation race is produced deterministically by inserting the competing row from inside
 * the model's `creating` event — the exact moment a real competitor would have won.
 */
class InboundConversationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function service(): InboundConversationService
    {
        return app(InboundConversationService::class);
    }

    private function message(array $overrides = []): InboundConversationMessage
    {
        return new InboundConversationMessage(
            provider: $overrides['provider'] ?? 'meta',
            channel: $overrides['channel'] ?? 'messenger',
            externalConversationId: $overrides['externalConversationId'] ?? 't_3001',
            externalMessageId: array_key_exists('externalMessageId', $overrides) ? $overrides['externalMessageId'] : 'mid_b',
            externalEventId: 'evt_b',
            senderExternalId: $overrides['senderExternalId'] ?? 'psid_900',
            recipientExternalId: 'page_42',
            senderName: 'Ana Cruz',
            senderEmail: $overrides['senderEmail'] ?? null,
            senderPhone: null,
            messageType: 'text',
            text: $overrides['text'] ?? 'Message B',
            occurredAt: $overrides['occurredAt'] ?? '2026-09-20T10:00:00+00:00',
            rawPayload: $overrides['rawPayload'] ?? ['delivery' => 'B'],
        );
    }

    /** The row a competing request would have committed first, written without model events. */
    private function competingConversationWins(string $externalConversationId = 't_3001'): void
    {
        $orgId = CurrentOrg::id();
        $inserted = false;

        Conversation::creating(function () use (&$inserted, $orgId, $externalConversationId): void {
            if ($inserted) {
                return;
            }

            $inserted = true;

            DB::table('crm_conversations')->insert([
                'org_id' => $orgId,
                'provider' => 'meta',
                'channel' => 'messenger',
                'external_conversation_id' => $externalConversationId,
                'external_contact_id' => 'psid_OTHER',
                'lead_id' => null,
                'status' => 'open',
                'started_at' => '2026-09-20 09:00:00',
                'last_message_at' => '2026-09-20 09:00:00',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * 30 — another request wins creation of the SAME conversation while this request carries a
     * DIFFERENT message. The message must still be persisted, on the winner's thread.
     */
    public function test_a_lost_conversation_creation_race_still_persists_the_current_message(): void
    {
        $this->competingConversationWins();

        $result = $this->service()->ingest($this->message());

        $this->assertTrue($result->wasCreated(), 'the message is new work, not a replay');
        $this->assertSame(1, Conversation::count(), 'the winner’s thread is reused, not duplicated');
        $this->assertSame(1, ConversationMessage::count());

        $conversation = Conversation::firstOrFail();
        $stored = ConversationMessage::firstOrFail();

        $this->assertSame($conversation->id, $stored->conversation_id);
        $this->assertSame('Message B', $stored->body);
        $this->assertSame('psid_OTHER', $conversation->external_contact_id, 'the winner’s contact id is not overwritten');
    }

    /** 31 — a conversation collision must never be reported as a message replay. */
    public function test_a_conversation_collision_is_not_classified_as_a_replay(): void
    {
        $this->competingConversationWins();

        $result = $this->service()->ingest($this->message());

        $this->assertFalse($result->wasReplay());
        $this->assertNotSame('replay', $result->leadMatchStrategy);
        $this->assertNotNull($result->message->id);
    }

    /** 30b — the recovered thread still receives later messages normally. */
    public function test_ingest_continues_normally_after_recovering_from_the_race(): void
    {
        $this->competingConversationWins();

        $this->service()->ingest($this->message(['externalMessageId' => 'mid_b1', 'text' => 'first']));
        $this->service()->ingest($this->message(['externalMessageId' => 'mid_b2', 'text' => 'second']));

        $this->assertSame(1, Conversation::count());
        $this->assertSame(2, ConversationMessage::count());
    }

    /**
     * 32 — an existing message identity resolves to a replay, with no second Activity and no
     * second message row.
     *
     * WHAT THIS TEST CANNOT DO, STATED PLAINLY. The other half of the replay logic — the branch
     * where the pre-check misses and the UNIQUE INDEX catches the duplicate at insert time — is not
     * simulable on this suite's single-connection SQLite. A competitor row written from inside our
     * own transaction is rolled back together with our failed insert, so the recovery would find
     * nothing; a second connection cannot help either, because SQLite locks the file for the
     * duration of our write transaction. Reaching that branch requires a genuinely separate,
     * already-committed transaction, which means MariaDB. {@see
     * test_a_unique_violation_with_nothing_to_recover_surfaces_as_a_sanitized_failure} pins the
     * safety property of that branch that IS reachable here: it never silently swallows.
     */
    public function test_an_existing_message_identity_returns_a_replay(): void
    {
        $message = $this->message();

        $first = $this->service()->ingest($message);
        $second = $this->service()->ingest($message);

        $this->assertTrue($first->wasCreated());
        $this->assertTrue($second->wasReplay());
        $this->assertSame('replay', $second->leadMatchStrategy);
        $this->assertSame($first->message->id, $second->message->id);
        $this->assertSame(1, ConversationMessage::count());
        $this->assertSame(
            1,
            Activity::query()->where('event', 'conversation.message_received')->count(),
            'the replay must not add a second audit entry',
        );
    }

    /**
     * 32b — if the unique index rejects a message and no matching row can be recovered, the ingest
     * must FAIL LOUDLY with the sanitized exception rather than reporting a replay it cannot prove.
     *
     * The situation is produced here by inserting the competing row inside this transaction, so it
     * disappears with the rollback — an artificial state, but it exercises exactly the guard that
     * decides between "recoverable duplicate" and "unexplained violation".
     */
    public function test_a_unique_violation_with_nothing_to_recover_surfaces_as_a_sanitized_failure(): void
    {
        $message = $this->message();
        $key = app(ConversationIdempotency::class)->keyFor($message);
        $orgId = CurrentOrg::id();
        $inserted = false;

        ConversationMessage::creating(function (ConversationMessage $model) use (&$inserted, $orgId, $key): void {
            if ($inserted) {
                return;
            }

            $inserted = true;

            DB::table('crm_messages')->insert([
                'org_id' => $orgId,
                'conversation_id' => $model->conversation_id,
                'provider' => 'meta',
                'channel' => 'messenger',
                'external_message_id' => 'mid_b',
                'idempotency_key' => $key,
                'direction' => 'inbound',
                'message_type' => 'text',
                'body' => 'the winner',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        });

        $this->expectException(InboundConversationPersistenceException::class);
        $this->expectExceptionMessageMatches('/SQLSTATE 23000/');

        $this->service()->ingest($message);
    }

    /** 33 — the newest timestamp always wins, whatever order the messages arrive in. */
    public function test_last_message_at_always_holds_the_maximum_timestamp(): void
    {
        $this->service()->ingest($this->message(['externalMessageId' => 'm1', 'occurredAt' => '2026-09-20T12:00:00+00:00']));
        $this->service()->ingest($this->message(['externalMessageId' => 'm2', 'occurredAt' => '2026-09-20T06:00:00+00:00']));
        $this->service()->ingest($this->message(['externalMessageId' => 'm3', 'occurredAt' => '2026-09-20T15:00:00+00:00']));
        $this->service()->ingest($this->message(['externalMessageId' => 'm4', 'occurredAt' => '2026-09-20T07:00:00+00:00']));

        $this->assertSame(
            '2026-09-20 15:00:00',
            Conversation::firstOrFail()->last_message_at?->utc()->format('Y-m-d H:i:s'),
        );
    }

    /** 34 — started_at always holds the earliest timestamp seen for the thread. */
    public function test_started_at_always_holds_the_earliest_timestamp(): void
    {
        $this->service()->ingest($this->message(['externalMessageId' => 'm1', 'occurredAt' => '2026-09-20T12:00:00+00:00']));
        $this->service()->ingest($this->message(['externalMessageId' => 'm2', 'occurredAt' => '2026-09-20T04:00:00+00:00']));
        $this->service()->ingest($this->message(['externalMessageId' => 'm3', 'occurredAt' => '2026-09-20T09:00:00+00:00']));

        $this->assertSame(
            '2026-09-20 04:00:00',
            Conversation::firstOrFail()->started_at?->utc()->format('Y-m-d H:i:s'),
        );
    }

    /** 35 — an association already on the thread is never replaced by a newer match. */
    public function test_an_existing_lead_association_cannot_be_overwritten(): void
    {
        $user = User::factory()->create();
        $associated = Lead::factory()->create(['email' => null, 'created_by' => $user->id]);
        $other = Lead::factory()->create(['email' => 'other@example.com', 'created_by' => $user->id]);

        Conversation::factory()->forLead($associated->id)->create([
            'provider' => 'meta',
            'channel' => 'messenger',
            'external_conversation_id' => 't_3001',
        ]);

        $result = $this->service()->ingest($this->message(['senderEmail' => 'other@example.com']));

        $this->assertSame(ConversationLeadMatcher::STRATEGY_STICKY, $result->leadMatchStrategy);
        $this->assertSame($associated->id, Conversation::firstOrFail()->lead_id);
        $this->assertNotSame($other->id, Conversation::firstOrFail()->lead_id);
    }

    /**
     * 36 — the event is held until the OUTERMOST transaction commits.
     *
     * A real listener is used rather than Event::fake(): the fake records a dispatch immediately
     * and would therefore report success even if the after-commit contract were broken.
     */
    public function test_the_domain_event_fires_only_after_the_outermost_transaction_commits(): void
    {
        $seen = 0;
        Event::listen(InboundConversationMessageReceived::class, function () use (&$seen): void {
            $seen++;
        });

        $this->service()->ingest($this->message(['externalMessageId' => 'committed']));
        $this->assertSame(1, $seen, 'a committed ingest announces itself exactly once');

        try {
            DB::transaction(function (): void {
                $this->service()->ingest($this->message(['externalMessageId' => 'rolled_back', 'text' => 'never stored']));

                throw new RuntimeException('force a rollback of the outer batch');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(1, $seen, 'a rolled-back ingest must announce nothing');
        $this->assertSame(1, ConversationMessage::count(), 'and must leave no message behind');
    }

    /** 36b — a replay neither registers nor dispatches a second event. */
    public function test_a_replay_registers_no_event(): void
    {
        $seen = 0;
        Event::listen(InboundConversationMessageReceived::class, function () use (&$seen): void {
            $seen++;
        });

        $this->service()->ingest($this->message());
        $this->service()->ingest($this->message());

        $this->assertSame(1, $seen);
    }

    /**
     * 37 — a failed write must not carry the message body or the provider payload into the
     * application-level exception (and from there into the log).
     */
    public function test_a_persistence_failure_exposes_no_body_or_payload(): void
    {
        // Force a NOT NULL violation — a QueryException that is deliberately NOT a unique
        // violation, so it takes the sanitizing path rather than the replay path.
        ConversationMessage::creating(function (ConversationMessage $model): void {
            $model->conversation_id = null;
        });

        $secret = 'CONFIDENTIAL-BODY-TEXT';
        $payloadMarker = 'CONFIDENTIAL-PAYLOAD-MARKER';

        try {
            $this->service()->ingest($this->message([
                'text' => $secret,
                'rawPayload' => ['note' => $payloadMarker],
            ]));

            $this->fail('the ingest should have failed');
        } catch (InboundConversationPersistenceException $exception) {
            $message = $exception->getMessage();

            $this->assertStringNotContainsString($secret, $message);
            $this->assertStringNotContainsString($payloadMarker, $message);
            $this->assertStringNotContainsString('insert into', strtolower($message));
            $this->assertNull($exception->getPrevious(), 'chaining the QueryException would reprint its bindings');

            $this->assertStringContainsString('SQLSTATE', $message);
            $this->assertStringContainsString('provider=meta', $message);
            $this->assertStringContainsString('channel=messenger', $message);
            $this->assertStringContainsString('external_conversation_id=t_3001', $message);
        }

        $this->assertSame(0, ConversationMessage::count());
    }
}
