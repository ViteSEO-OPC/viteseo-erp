<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Inbound\ConversationIdempotency;
use App\Modules\Crm\Services\Inbound\Meta\Adapters\MetaMessengerAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Inbound\Meta\MetaEnvelopes;
use Tests\Support\Inbound\Meta\SignsMetaDeliveries;
use Tests\TestCase;

/**
 * The whole path, end to end: a signed HTTP delivery becoming rows, or deliberately not.
 *
 * The per-item ceilings get the most attention here, because this route is the one door into the
 * conversation engine that does NOT pass through the Phase 3B form request where those limits were
 * originally enforced. Without them re-applied in the Meta layer, the public webhook would be the
 * single place where an oversized or arbitrarily deep payload reaches the database.
 */
class MetaWebhookPipelineTest extends TestCase
{
    use RefreshDatabase;
    use SignsMetaDeliveries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMetaWebhook();
        $this->connectMetaResource($this->tenant()->id, MetaEnvelopes::PAGE_A);
        $this->connectMetaResource($this->tenant()->id, MetaEnvelopes::IG_A, 'instagram_business_account');
    }

    public function test_a_messenger_delivery_becomes_a_conversation_and_a_message(): void
    {
        $this->postSigned(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()]))->assertOk();

        $message = ConversationMessage::firstOrFail();
        $conversation = Conversation::firstOrFail();

        $this->assertSame('meta', $message->provider);
        $this->assertSame('messenger', $message->channel);
        $this->assertSame('mid.text1', $message->external_message_id);
        $this->assertSame('inbound', $message->direction);
        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertSame('fbpage:'.MetaEnvelopes::PAGE_A.':from:'.MetaEnvelopes::PSID, $conversation->external_conversation_id);
    }

    public function test_an_instagram_delivery_lands_on_its_own_channel(): void
    {
        $this->postSigned(MetaEnvelopes::instagram(MetaEnvelopes::IG_A, [MetaEnvelopes::igTextItem()]))->assertOk();

        $this->assertSame('instagram_dm', ConversationMessage::firstOrFail()->channel);
        $this->assertSame('instagram_dm', Conversation::firstOrFail()->channel);
    }

    /** Inbound never creates or moves a lead — the standing rule, checked on this path too. */
    public function test_ingestion_creates_no_lead_and_touches_no_status(): void
    {
        $this->postSigned(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()]))->assertOk();

        $this->assertSame(0, Lead::count());
        $this->assertNull(Conversation::firstOrFail()->lead_id);
    }

    // ------------------------------------------------------------------ idempotency

    public function test_a_replayed_messenger_delivery_creates_no_second_row(): void
    {
        $envelope = MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()]);

        $this->postSigned($envelope)->assertOk();
        $this->postSigned($envelope)->assertOk();

        $this->assertSame(1, ConversationMessage::count());
        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, Activity::count(), 'the side effects happened exactly once');
    }

    public function test_a_replayed_instagram_delivery_creates_no_second_row(): void
    {
        $envelope = MetaEnvelopes::instagram(MetaEnvelopes::IG_A, [MetaEnvelopes::igTextItem()]);

        $this->postSigned($envelope)->assertOk();
        $this->postSigned($envelope)->assertOk();

        $this->assertSame(1, ConversationMessage::count());
    }

    /**
     * Three mid-less siblings in one delivery must be three rows.
     *
     * They fall through to the Phase 3A derived key, which hashes the item payload — so this is the
     * assertion that the adapter really does give each turn its own slice rather than the shared
     * envelope. A regression here would silently swallow two real customer messages.
     */
    public function test_three_midless_siblings_become_three_messages_and_replay_cleanly(): void
    {
        $envelope = MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [
            MetaEnvelopes::midlessItem('una'),
            MetaEnvelopes::midlessItem('pangalawa'),
            MetaEnvelopes::midlessItem('pangatlo'),
        ]);

        $this->postSigned($envelope)->assertOk();

        $this->assertSame(3, ConversationMessage::count());
        $this->assertCount(3, array_unique(ConversationMessage::query()->pluck('idempotency_key')->all()));

        $this->postSigned($envelope)->assertOk();

        $this->assertSame(3, ConversationMessage::count(), 'the replay adds nothing');
    }

    /** The key on the row is the engine's, derived from the adapter's DTO — never the adapter's own. */
    public function test_phase_3a_still_owns_the_idempotency_key(): void
    {
        $item = MetaEnvelopes::textItem();
        $expected = $this->app->make(ConversationIdempotency::class)->keyFor(
            (new MetaMessengerAdapter)->normalizeEnvelope(['items' => [$item]])->messages[0],
        );

        $this->postSigned(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [$item]))->assertOk();

        $this->assertSame($expected, ConversationMessage::firstOrFail()->idempotency_key);
    }

    // ------------------------------------------------------------------ per-item limits

    /** The encoded-size ceiling the trusted boundary applies, re-applied here. */
    public function test_an_oversized_item_is_refused_without_blocking_the_delivery(): void
    {
        config(['inbound.limits.raw_payload_bytes' => 2048]);

        $big = MetaEnvelopes::textItem('mid.big');
        $big['message']['blob'] = str_repeat('x', 8192);

        $this->postSigned(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [
            MetaEnvelopes::textItem('mid.ok1', 'fine'),
            $big,
            MetaEnvelopes::textItem('mid.ok2', 'also fine'),
        ]))->assertOk();

        $this->assertSame(
            ['mid.ok1', 'mid.ok2'],
            ConversationMessage::query()->orderBy('id')->pluck('external_message_id')->all(),
        );
    }

    public function test_an_over_deep_item_is_refused(): void
    {
        // The envelope-level ceiling is raised so this test exercises the PER-ITEM rule rather
        // than the json_decode depth guard, which would otherwise answer 400 first (as the
        // security suite proves it does).
        config(['meta.limits.json_depth' => 64, 'inbound.limits.raw_payload_depth' => 5]);

        $this->postSigned(MetaEnvelopes::deepItem(6))->assertOk();

        $this->assertSame(0, ConversationMessage::count());
    }

    /** An over-length message is rejected, never shortened: a truncated quote is a false record. */
    public function test_an_over_length_text_is_rejected_not_truncated(): void
    {
        config(['inbound.limits.text' => 50]);

        $this->postSigned(MetaEnvelopes::longTextItem(500))->assertOk();

        $this->assertSame(0, ConversationMessage::count(), 'nothing is stored');
        $this->assertSame(0, DB::table('crm_messages')->whereNotNull('body')->count(), 'and nothing is stored shortened');
    }

    public function test_text_exactly_at_the_limit_is_accepted(): void
    {
        config(['inbound.limits.text' => 50]);

        $this->postSigned(MetaEnvelopes::longTextItem(50))->assertOk();

        $this->assertSame(1, ConversationMessage::count());
        $this->assertSame(50, mb_strlen((string) ConversationMessage::firstOrFail()->body));
    }

    // ------------------------------------------------------------------ mixed outcomes

    /** Receipts and echoes are acknowledged and written nowhere; the real turn still lands. */
    public function test_a_realistic_mixed_delivery_keeps_only_the_customer_turn(): void
    {
        $this->postSigned(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [
            MetaEnvelopes::deliveryItem(),
            MetaEnvelopes::textItem('mid.real', 'the only real one'),
            MetaEnvelopes::readItem(),
            MetaEnvelopes::echoItem(),
        ]))->assertOk();

        $this->assertSame(1, ConversationMessage::count());
        $this->assertSame('mid.real', ConversationMessage::firstOrFail()->external_message_id);
    }

    public function test_a_malformed_child_does_not_cost_its_siblings(): void
    {
        $this->postSigned(MetaEnvelopes::malformedSibling())->assertOk();

        $this->assertSame(
            ['mid.good1', 'mid.good2'],
            ConversationMessage::query()->orderBy('id')->pluck('external_message_id')->all(),
        );
    }

    /** The raw payload stored is the ITEM, not the delivery. */
    public function test_the_stored_payload_is_the_item_slice(): void
    {
        $item = MetaEnvelopes::textItem();

        $this->postSigned(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [$item]))->assertOk();

        $stored = ConversationMessage::firstOrFail()->payload;

        $this->assertSame($item, $stored);
        $this->assertArrayNotHasKey('entry', $stored);
        $this->assertArrayNotHasKey('object', $stored);
    }

    /** An unknown top-level object is acknowledged and writes nothing. */
    public function test_an_unknown_object_writes_nothing(): void
    {
        $this->postSigned(MetaEnvelopes::unknownObject())->assertOk();

        $this->assertSame(0, ConversationMessage::count());
        $this->assertSame(0, Conversation::count());
    }
}
