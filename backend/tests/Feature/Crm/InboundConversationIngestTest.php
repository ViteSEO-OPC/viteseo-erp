<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Events\InboundConversationMessageReceived;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Inbound\Contracts\InboundConversationProvider;
use App\Modules\Crm\Services\Inbound\InboundConversationMessage;
use App\Modules\Crm\Services\Inbound\InboundConversationService;
use App\Support\CurrentOrg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The inbound conversation pipeline, pinned at the service layer — no HTTP surface exists yet and
 * no provider credentials are involved. A hand-built normalized message stands in for whatever a
 * future Meta adapter will produce, which is the point of the contract: the pipeline is provable
 * before the integration exists.
 *
 * The rules under test are the ones that make at-least-once delivery safe: identity is scoped by
 * provider AND channel, a replay writes nothing and fires nothing, and recency only moves forward.
 */
class InboundConversationIngestTest extends TestCase
{
    use RefreshDatabase;

    private function service(): InboundConversationService
    {
        return app(InboundConversationService::class);
    }

    /**
     * `externalMessageId` is read with array_key_exists, not `??`: passing an explicit null is how
     * a test says "this provider issues no message id", and `??` would silently hand it the
     * default instead — exercising the wrong branch of the idempotency rule.
     */
    private function message(array $overrides = []): InboundConversationMessage
    {
        return new InboundConversationMessage(
            provider: $overrides['provider'] ?? 'meta',
            channel: $overrides['channel'] ?? 'messenger',
            externalConversationId: $overrides['externalConversationId'] ?? 't_1001',
            externalMessageId: array_key_exists('externalMessageId', $overrides) ? $overrides['externalMessageId'] : 'mid_1',
            externalEventId: $overrides['externalEventId'] ?? 'evt_1',
            senderExternalId: $overrides['senderExternalId'] ?? 'psid_777',
            recipientExternalId: $overrides['recipientExternalId'] ?? 'page_42',
            senderName: $overrides['senderName'] ?? 'Maria Santos',
            senderEmail: $overrides['senderEmail'] ?? null,
            senderPhone: $overrides['senderPhone'] ?? null,
            messageType: $overrides['messageType'] ?? 'text',
            text: $overrides['text'] ?? 'Magkano po ang service niyo?',
            occurredAt: $overrides['occurredAt'] ?? '2026-09-20T08:00:00+00:00',
            rawPayload: $overrides['rawPayload'] ?? ['object' => 'page', 'entry' => [['id' => 'page_42']]],
            direction: $overrides['direction'] ?? 'inbound',
        );
    }

    /** 1 + 2 — a first message opens a thread and is stored on it. */
    public function test_a_new_inbound_message_creates_a_conversation_and_persists_the_message(): void
    {
        $result = $this->service()->ingest($this->message());

        $this->assertTrue($result->wasCreated());
        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, ConversationMessage::count());

        $conversation = Conversation::firstOrFail();
        $this->assertSame('meta', $conversation->provider);
        $this->assertSame('messenger', $conversation->channel);
        $this->assertSame('t_1001', $conversation->external_conversation_id);
        $this->assertSame('psid_777', $conversation->external_contact_id);
        $this->assertSame('open', $conversation->status);
        $this->assertNull($conversation->lead_id);

        $stored = ConversationMessage::firstOrFail();
        $this->assertSame($conversation->id, $stored->conversation_id);
        $this->assertSame('mid_1', $stored->external_message_id);
        $this->assertSame('inbound', $stored->direction);
        $this->assertSame('Magkano po ang service niyo?', $stored->body);
        $this->assertNotEmpty($stored->idempotency_key);
        $this->assertNotNull($stored->received_at);
        $this->assertSame('2026-09-20 08:00:00', $stored->occurred_at?->utc()->format('Y-m-d H:i:s'));
    }

    /** 3 — the same delivery arriving twice is the same message, not two. */
    public function test_replaying_the_same_event_does_not_duplicate_the_message(): void
    {
        $first = $this->service()->ingest($this->message());
        $second = $this->service()->ingest($this->message());

        $this->assertTrue($first->wasCreated());
        $this->assertTrue($second->wasReplay());
        $this->assertSame($first->message->id, $second->message->id);
        $this->assertSame(1, ConversationMessage::count());
        $this->assertSame(1, Conversation::count());
    }

    /** 3b — a provider that issues no message id is still replay-safe through the derived key. */
    public function test_replay_is_safe_for_a_provider_that_issues_no_message_id(): void
    {
        $message = $this->message(['externalMessageId' => null]);

        $first = $this->service()->ingest($message);
        $second = $this->service()->ingest($message);

        $this->assertTrue($first->wasCreated());
        $this->assertTrue($second->wasReplay());
        $this->assertSame(1, ConversationMessage::count());
    }

    /** 3c — two genuinely different messages in the same second are NOT collapsed. */
    public function test_two_different_messages_at_the_same_timestamp_are_both_kept(): void
    {
        $this->service()->ingest($this->message(['externalMessageId' => null, 'text' => 'First question']));
        $this->service()->ingest($this->message(['externalMessageId' => null, 'text' => 'Second question']));

        $this->assertSame(2, ConversationMessage::count());
    }

    /** 4 — one id, two vendors: different namespaces, both must survive. */
    public function test_the_same_external_message_id_from_different_providers_does_not_collide(): void
    {
        $this->service()->ingest($this->message(['provider' => 'meta', 'externalMessageId' => 'shared_id']));
        $this->service()->ingest($this->message([
            'provider' => 'twilio',
            'channel' => 'whatsapp',
            'externalConversationId' => 'wa_1',
            'externalMessageId' => 'shared_id',
        ]));

        $this->assertSame(2, ConversationMessage::count());
        $this->assertSame(2, Conversation::count());
    }

    /** 11 — one vendor, two channels, same id: still two messages. */
    public function test_the_same_external_message_id_on_different_channels_does_not_collide(): void
    {
        $this->service()->ingest($this->message([
            'channel' => 'messenger',
            'externalConversationId' => 't_a',
            'externalMessageId' => 'mid_same',
        ]));
        $this->service()->ingest($this->message([
            'channel' => 'instagram_dm',
            'externalConversationId' => 'ig_a',
            'externalMessageId' => 'mid_same',
        ]));

        $this->assertSame(2, ConversationMessage::count());
        $this->assertSame(
            ['instagram_dm', 'messenger'],
            ConversationMessage::query()->orderBy('channel')->pluck('channel')->all(),
        );
    }

    /** 12 — one vendor, two channels, same thread id: two distinct threads. */
    public function test_the_same_external_conversation_id_on_different_channels_creates_distinct_conversations(): void
    {
        $this->service()->ingest($this->message([
            'channel' => 'messenger',
            'externalConversationId' => 'thread_shared',
            'externalMessageId' => 'mid_a',
        ]));
        $this->service()->ingest($this->message([
            'channel' => 'instagram_dm',
            'externalConversationId' => 'thread_shared',
            'externalMessageId' => 'mid_b',
        ]));

        $this->assertSame(2, Conversation::count());
        $this->assertSame(
            ['instagram_dm', 'messenger'],
            Conversation::query()->orderBy('channel')->pluck('channel')->all(),
        );
    }

    /** 5 — a second turn joins the existing thread and moves its recency forward. */
    public function test_an_existing_conversation_receives_a_second_message(): void
    {
        $this->service()->ingest($this->message([
            'externalMessageId' => 'mid_1',
            'occurredAt' => '2026-09-20T08:00:00+00:00',
        ]));
        $this->service()->ingest($this->message([
            'externalMessageId' => 'mid_2',
            'text' => 'May follow-up po ako',
            'occurredAt' => '2026-09-20T09:30:00+00:00',
        ]));

        $this->assertSame(1, Conversation::count());
        $this->assertSame(2, ConversationMessage::count());

        $conversation = Conversation::firstOrFail();
        $this->assertSame('2026-09-20 09:30:00', $conversation->last_message_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-20 08:00:00', $conversation->started_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(2, $conversation->messages()->count());
    }

    /** 8 — normalization does not throw the provider's own event away. */
    public function test_the_raw_provider_payload_is_retained(): void
    {
        $payload = [
            'object' => 'page',
            'entry' => [['id' => 'page_42', 'messaging' => [['message' => ['mid' => 'mid_1', 'text' => 'hi']]]]],
        ];

        $this->service()->ingest($this->message(['rawPayload' => $payload]));

        $this->assertSame($payload, ConversationMessage::firstOrFail()->payload);
    }

    /** 9 — ingest is not allowed to move the sales pipeline on its own. */
    public function test_ingest_never_mutates_lead_status_or_ownership(): void
    {
        $owner = User::factory()->create();
        $lead = Lead::factory()->create([
            'email' => 'maria@example.com',
            'status' => 'new',
            'assigned_to' => null,
            'created_by' => $owner->id,
        ]);

        $this->service()->ingest($this->message(['senderEmail' => 'maria@example.com']));

        $lead->refresh();
        $this->assertSame('new', $lead->status);
        $this->assertNull($lead->assigned_to);
        $this->assertSame($lead->id, Conversation::firstOrFail()->lead_id, 'the thread should still be associated');
    }

    /** 13 — a replay must not add a second entry to the audit trail. */
    public function test_replay_does_not_create_a_duplicate_activity(): void
    {
        $this->service()->ingest($this->message());
        $after = Activity::query()->where('event', 'conversation.message_received')->count();

        $this->service()->ingest($this->message());

        $this->assertSame(1, $after);
        $this->assertSame($after, Activity::query()->where('event', 'conversation.message_received')->count());
    }

    /** 14 — post-processing runs once per message, never again on a replay. */
    public function test_replay_does_not_run_post_processing_twice(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');
        $this->service()->ingest($this->message());
        $processedAt = ConversationMessage::firstOrFail()->processed_at;
        $this->assertNotNull($processedAt);

        Carbon::setTestNow('2026-09-20 11:00:00');
        $replay = $this->service()->ingest($this->message());

        $this->assertTrue($replay->wasReplay());
        $this->assertSame(
            $processedAt->format('Y-m-d H:i:s'),
            ConversationMessage::firstOrFail()->processed_at?->format('Y-m-d H:i:s'),
        );

        Carbon::setTestNow();
    }

    /**
     * 14b — a replay fires no domain event, so nothing downstream doubles either.
     *
     * A real listener, not Event::fake(): the fake records a dispatch the moment it is called and
     * would report success even if the event's after-commit contract were broken.
     */
    public function test_replay_dispatches_no_inbound_event(): void
    {
        $seen = 0;
        Event::listen(InboundConversationMessageReceived::class, function () use (&$seen): void {
            $seen++;
        });

        $this->service()->ingest($this->message());
        $this->service()->ingest($this->message());

        $this->assertSame(1, $seen);
    }

    /** 18 — a late or out-of-order delivery must not drag the thread backwards in the inbox. */
    public function test_an_older_message_cannot_move_last_message_at_backwards(): void
    {
        $this->service()->ingest($this->message([
            'externalMessageId' => 'mid_new',
            'occurredAt' => '2026-09-20T12:00:00+00:00',
        ]));

        // An older message that is genuinely new to us…
        $this->service()->ingest($this->message([
            'externalMessageId' => 'mid_old',
            'occurredAt' => '2026-09-20T06:00:00+00:00',
        ]));

        // …and a replay of that older one.
        $this->service()->ingest($this->message([
            'externalMessageId' => 'mid_old',
            'occurredAt' => '2026-09-20T06:00:00+00:00',
        ]));

        $conversation = Conversation::firstOrFail();
        $this->assertSame('2026-09-20 12:00:00', $conversation->last_message_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-20 06:00:00', $conversation->started_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(2, ConversationMessage::count());
    }

    /** A new inbound message announces itself exactly once, for later phases to hook. */
    public function test_a_new_inbound_message_dispatches_the_domain_event(): void
    {
        $seen = [];
        Event::listen(
            InboundConversationMessageReceived::class,
            function (InboundConversationMessageReceived $event) use (&$seen): void {
                $seen[] = $event->message->id;
            },
        );

        $result = $this->service()->ingest($this->message());

        $this->assertSame([$result->message->id], $seen);
    }

    /** The pipeline consumes an adapter's output without knowing anything about its payload shape. */
    public function test_a_provider_adapter_feeds_the_pipeline_without_the_pipeline_knowing_the_provider(): void
    {
        $adapter = new FakeMessengerLikeProvider;
        $this->assertTrue($adapter->supports('fake', 'chat'));
        $this->assertFalse($adapter->supports('fake', 'email'));

        $payload = ['events' => [
            ['id' => 'x1', 'thread' => 'th_1', 'from' => 'u_1', 'text' => 'Hello po'],
            ['id' => 'x2', 'thread' => 'th_1', 'from' => 'u_1', 'text' => 'Available pa ba?'],
        ]];

        foreach ($adapter->normalize($payload) as $normalized) {
            $this->service()->ingest($normalized);
        }

        $this->assertSame(1, Conversation::count());
        $this->assertSame(2, ConversationMessage::count());

        // …and the same delivery again changes nothing.
        foreach ($adapter->normalize($payload) as $normalized) {
            $this->service()->ingest($normalized);
        }

        $this->assertSame(2, ConversationMessage::count());
    }

    /** Ingest refuses to guess a tenant rather than writing into the wrong one. */
    public function test_ingest_refuses_to_run_without_an_org_context(): void
    {
        CurrentOrg::set(null);

        $this->expectException(\RuntimeException::class);

        try {
            $this->service()->ingest($this->message());
        } finally {
            CurrentOrg::set($this->tenantId());
        }
    }

    private function tenantId(): int
    {
        return (int) Org::query()->orderBy('id')->value('id');
    }
}

/**
 * Stand-in for a real provider adapter. Its payload shape is invented for this test precisely to
 * prove the pipeline never inspects one — only the normalized contract crosses the boundary.
 */
final class FakeMessengerLikeProvider implements InboundConversationProvider
{
    public function supports(string $provider, string $channel): bool
    {
        return $provider === 'fake' && $channel === 'chat';
    }

    public function normalize(array $payload): iterable
    {
        foreach ($payload['events'] ?? [] as $event) {
            yield new InboundConversationMessage(
                provider: 'fake',
                channel: 'chat',
                externalConversationId: (string) $event['thread'],
                externalMessageId: (string) $event['id'],
                externalEventId: 'delivery_1',
                senderExternalId: (string) $event['from'],
                messageType: 'text',
                text: (string) $event['text'],
                occurredAt: '2026-09-20T08:00:00+00:00',
                rawPayload: $event,
            );
        }
    }
}
