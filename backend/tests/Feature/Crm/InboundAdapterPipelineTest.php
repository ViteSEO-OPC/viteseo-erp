<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Services\Inbound\Adapters\RejectionCode;
use App\Modules\Crm\Services\Inbound\Adapters\UnsupportedProviderChannelException;
use App\Modules\Crm\Services\Inbound\ConversationIdempotency;
use App\Modules\Crm\Services\Inbound\InboundNormalizationPipeline;
use App\Support\CurrentOrg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Inbound\FixtureEnvelopes;
use Tests\Support\Inbound\FixtureInboundProviderAdapter;
use Tests\TestCase;

/**
 * The adapter layer joined to the Phase 3A engine, proven by rows rather than by return values.
 *
 * The case this file exists for is the one that would be invisible in a unit test: three sibling
 * messages with NO provider message id. Phase 3A derives their idempotency key by hashing the raw
 * payload, so an adapter that handed every sibling the same envelope would make all three hash
 * alike and two of the three would land as replays — a silent loss of real customer messages that
 * no assertion about normalization output could catch.
 */
class InboundAdapterPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inbound.adapters' => [FixtureInboundProviderAdapter::class]]);
    }

    private function pipeline(): InboundNormalizationPipeline
    {
        return $this->app->make(InboundNormalizationPipeline::class);
    }

    /** 17 — three id-less siblings become three CRM messages. */
    public function test_three_id_less_siblings_create_three_messages(): void
    {
        $result = $this->pipeline()->process('fixture', 'chat', FixtureEnvelopes::threeWithoutMessageIds());

        $this->assertSame(3, $result->createdCount());
        $this->assertSame(0, $result->replayedCount());
        $this->assertSame(3, ConversationMessage::count());
        $this->assertSame(1, Conversation::count(), 'all three belong to the one thread they name');

        $keys = ConversationMessage::query()->pluck('idempotency_key')->all();
        $this->assertCount(3, array_unique($keys), 'each sibling derives its own key');
    }

    /** 18 — replaying the same envelope adds nothing. */
    public function test_replaying_the_envelope_keeps_exactly_three_messages(): void
    {
        $envelope = FixtureEnvelopes::threeWithoutMessageIds();

        $this->pipeline()->process('fixture', 'chat', $envelope);
        $replay = $this->pipeline()->process('fixture', 'chat', $envelope);

        $this->assertSame(0, $replay->createdCount());
        $this->assertSame(3, $replay->replayedCount());
        $this->assertSame(3, ConversationMessage::count());
        $this->assertSame(1, Conversation::count());
    }

    /** 19 — the key on the row is the one Phase 3A computes, not one the adapter chose. */
    public function test_phase_3a_remains_the_sole_idempotency_key_owner(): void
    {
        $adapter = new FixtureInboundProviderAdapter;
        $envelope = FixtureEnvelopes::threeWithoutMessageIds();
        $expected = array_map(
            fn ($message): string => $this->app->make(ConversationIdempotency::class)->keyFor($message),
            $adapter->normalizeEnvelope($envelope)->messages,
        );

        $this->pipeline()->process('fixture', 'chat', $envelope);

        $this->assertSame(
            $expected,
            ConversationMessage::query()->orderBy('id')->pluck('idempotency_key')->all(),
        );
    }

    /** A provider that DOES issue message ids takes the other idempotency regime, still Phase 3A's. */
    public function test_messages_with_provider_ids_are_deduplicated_by_those_ids(): void
    {
        $envelope = FixtureEnvelopes::threeMessages();

        $this->pipeline()->process('fixture', 'chat', $envelope);
        $this->pipeline()->process('fixture', 'chat', $envelope);

        $this->assertSame(3, ConversationMessage::count());
        $this->assertSame(
            ['mid_1', 'mid_2', 'mid_3'],
            ConversationMessage::query()->orderBy('id')->pluck('external_message_id')->all(),
        );
    }

    /** 12 (at the row level) — the malformed sibling costs itself and nothing else. */
    public function test_a_malformed_sibling_does_not_block_its_valid_siblings(): void
    {
        $result = $this->pipeline()->process('fixture', 'chat', FixtureEnvelopes::malformedSibling());

        $this->assertSame(2, $result->createdCount());
        $this->assertSame(1, $result->rejectionCount());
        $this->assertSame(RejectionCode::MalformedEvent, $result->failures()[0]->code);
        $this->assertSame(
            ['mid_a', 'mid_c'],
            ConversationMessage::query()->orderBy('id')->pluck('external_message_id')->all(),
        );
    }

    /** 11 (at the row level) — an ignored receipt writes nothing and is not an error. */
    public function test_an_ignored_child_produces_no_crm_message(): void
    {
        $result = $this->pipeline()->process('fixture', 'chat', FixtureEnvelopes::ignoredReceipt());

        $this->assertSame(1, $result->createdCount());
        $this->assertSame(1, $result->ignoredCount());
        $this->assertSame(0, $result->rejectionCount());
        $this->assertSame(1, ConversationMessage::count());
    }

    /** The result describes the whole delivery, which is what a webhook will act on. */
    public function test_the_result_reports_the_whole_delivery(): void
    {
        $result = $this->pipeline()->process('fixture', 'chat', FixtureEnvelopes::malformedSibling());

        $this->assertSame('fixture', $result->provider);
        $this->assertSame('chat', $result->channel);
        $this->assertSame('fixture', $result->adapter);
        $this->assertSame(2, $result->ingestedCount());
        $this->assertFalse($result->hasRecoverableFailures(), 'a malformed item will never become valid');

        $diagnostics = $result->toDiagnostics();
        $this->assertSame(2, $diagnostics['created_count']);
        $this->assertSame(1, $diagnostics['rejection_count']);
        $this->assertFalse($diagnostics['has_recoverable_failures'], 'the layer reports the fact, never a retry verdict');
    }

    /** An unconfigured channel never reaches the engine. */
    public function test_an_unsupported_channel_writes_nothing(): void
    {
        try {
            $this->pipeline()->process('meta', 'messenger', FixtureEnvelopes::singleText());
            $this->fail('an unregistered pair must not be processed');
        } catch (UnsupportedProviderChannelException) {
            // expected
        }

        $this->assertSame(0, ConversationMessage::count());
        $this->assertSame(0, Conversation::count());
    }

    /** Normalized turns land in the caller's tenant, which the pipeline never chooses. */
    public function test_messages_land_in_the_pinned_tenant(): void
    {
        $this->pipeline()->process('fixture', 'chat', FixtureEnvelopes::singleText());

        $this->assertSame(CurrentOrg::id(), ConversationMessage::firstOrFail()->org_id);
        $this->assertSame(CurrentOrg::id(), Conversation::firstOrFail()->org_id);
    }

    /** The turn keeps its item-level payload all the way onto the row. */
    public function test_the_stored_payload_is_the_item_slice(): void
    {
        $envelope = FixtureEnvelopes::threeMessages();

        $this->pipeline()->process('fixture', 'chat', $envelope);

        $stored = ConversationMessage::query()->orderBy('id')->get();

        foreach ($stored as $position => $message) {
            $this->assertSame($envelope['events'][$position], $message->payload);
        }
    }
}
