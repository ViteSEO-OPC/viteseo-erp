<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Services\Inbound\Adapters\RejectionCode;
use App\Modules\Crm\Services\Inbound\InboundConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Inbound\FixtureEnvelopes;
use Tests\Support\Inbound\FixtureInboundProviderAdapter;
use Tests\TestCase;

/**
 * Normalization on its own — no database, no pipeline, no ingest.
 *
 * Everything here is about the shape of the answer: which items became turns, which were ignored,
 * which were rejected and with what code. Testing it at this level is what makes the pipeline
 * tests short; testing it only through the pipeline would leave the interesting cases (a malformed
 * sibling, an unsupported type) provable solely by counting rows afterwards.
 */
class InboundAdapterNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private FixtureInboundProviderAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new FixtureInboundProviderAdapter;
    }

    /** 5 — one event, one turn. */
    public function test_a_single_event_normalizes_to_one_message(): void
    {
        $result = $this->adapter->normalizeEnvelope(FixtureEnvelopes::singleText());

        $this->assertSame(1, $result->messageCount());
        $this->assertSame(0, $result->rejectionCount());
        $this->assertSame(0, $result->ignoredCount());
        $this->assertInstanceOf(InboundConversationMessage::class, $result->messages[0]);
    }

    /** 6 — one envelope, many turns. */
    public function test_an_envelope_normalizes_to_many_messages(): void
    {
        $result = $this->adapter->normalizeEnvelope(FixtureEnvelopes::threeMessages());

        $this->assertSame(3, $result->messageCount());
        $this->assertSame(
            ['mid_1', 'mid_2', 'mid_3'],
            array_map(fn (InboundConversationMessage $m): ?string => $m->externalMessageId, $result->messages),
        );
    }

    /** 7 — a text turn. */
    public function test_a_text_event_normalizes(): void
    {
        $message = $this->adapter->normalizeEnvelope(FixtureEnvelopes::singleText('Magkano po?'))->messages[0];

        $this->assertSame('text', $message->messageType);
        $this->assertSame('Magkano po?', $message->text);
        $this->assertTrue($message->isInbound());
    }

    /** 8 — an attachment turn: a different type, and no text is not an error. */
    public function test_an_attachment_event_normalizes(): void
    {
        $message = $this->adapter->normalizeEnvelope(FixtureEnvelopes::attachment())->messages[0];

        $this->assertSame('attachment', $message->messageType);
        $this->assertNull($message->text);
        $this->assertSame(['kind' => 'image', 'ref' => 'asset_77'], $message->rawPayload['attachment']);
    }

    /** 9 — a provider that issues no message id is supported, not rejected. */
    public function test_a_missing_external_message_id_is_supported(): void
    {
        $result = $this->adapter->normalizeEnvelope(FixtureEnvelopes::threeWithoutMessageIds());

        $this->assertSame(3, $result->messageCount());

        foreach ($result->messages as $message) {
            $this->assertNull($message->externalMessageId);
            $this->assertNotNull($message->externalConversationId);
        }
    }

    /** 10 — a malformed child is a rejection, not a thrown exception. */
    public function test_a_malformed_child_becomes_a_rejection(): void
    {
        $result = $this->adapter->normalizeEnvelope(FixtureEnvelopes::malformedSibling());

        $this->assertSame(1, $result->rejectionCount());
        $this->assertSame(RejectionCode::MalformedEvent, $result->failures()[0]->code);
        $this->assertSame(1, $result->failures()[0]->itemIndex, 'the rejection points at the offending item');
        $this->assertFalse($result->failures()[0]->recoverable, 'malformed data will still be malformed on a retry');
    }

    /** An envelope that is not a list of events at all is rejected without an item index. */
    public function test_a_malformed_envelope_is_rejected_safely(): void
    {
        $result = $this->adapter->normalizeEnvelope(FixtureEnvelopes::malformedEnvelope());

        $this->assertSame(0, $result->messageCount());
        $this->assertSame(1, $result->rejectionCount());
        $this->assertNull($result->failures()[0]->itemIndex);
    }

    /** 11 — a non-message event is IGNORED, which is not the same as rejected. */
    public function test_an_ignored_child_produces_no_message_and_no_failure(): void
    {
        $result = $this->adapter->normalizeEnvelope(FixtureEnvelopes::ignoredReceipt());

        $this->assertSame(1, $result->messageCount(), 'the real message beside the receipt still normalizes');
        $this->assertSame(1, $result->ignoredCount());
        $this->assertSame(0, $result->rejectionCount(), 'a receipt is normal traffic, not an error');
        $this->assertSame(RejectionCode::IgnoredNonMessage, $result->ignored()[0]->code);
    }

    /** 12 — the sibling rule, at the normalization level: A and C survive B. */
    public function test_a_malformed_sibling_does_not_block_valid_siblings(): void
    {
        $result = $this->adapter->normalizeEnvelope(FixtureEnvelopes::malformedSibling());

        $this->assertSame(
            ['mid_a', 'mid_c'],
            array_map(fn (InboundConversationMessage $m): ?string => $m->externalMessageId, $result->messages),
        );
    }

    /** 13 — provider and channel come from the adapter, never from the item. */
    public function test_provider_and_channel_are_preserved(): void
    {
        $message = $this->adapter->normalizeEnvelope(FixtureEnvelopes::singleText())->messages[0];

        $this->assertSame('fixture', $message->provider);
        $this->assertSame('chat', $message->channel);
    }

    /** An item claiming another channel is refused rather than relabelled. */
    public function test_an_item_from_an_unsupported_channel_is_rejected(): void
    {
        $result = $this->adapter->normalizeEnvelope(FixtureEnvelopes::unsupportedChannelItem());

        $this->assertSame(0, $result->messageCount());
        $this->assertSame(RejectionCode::UnsupportedChannel, $result->failures()[0]->code);
    }

    /** 14 — every external identifier survives normalization. */
    public function test_external_identifiers_are_preserved(): void
    {
        $message = $this->adapter->normalizeEnvelope(FixtureEnvelopes::singleText())->messages[0];

        $this->assertSame('t_1', $message->externalConversationId);
        $this->assertSame('mid_1', $message->externalMessageId);
        $this->assertSame('evt_mid_1', $message->externalEventId);
        $this->assertSame('psid_100', $message->senderExternalId);
        $this->assertSame('page_900', $message->recipientExternalId);
        $this->assertSame('Maria Santos', $message->senderName);
    }

    /** A turn with no thread cannot be placed, and is refused rather than given an invented one. */
    public function test_a_turn_without_a_thread_is_rejected(): void
    {
        $result = $this->adapter->normalizeEnvelope(FixtureEnvelopes::missingThread());

        $this->assertSame(RejectionCode::MissingProviderIdentity, $result->failures()[0]->code);
    }

    /** A type we cannot represent is named as such, not silently downgraded to text. */
    public function test_an_unsupported_message_type_is_rejected(): void
    {
        $result = $this->adapter->normalizeEnvelope(FixtureEnvelopes::unsupportedMessageType());

        $this->assertSame(RejectionCode::UnsupportedMessageType, $result->failures()[0]->code);
    }

    /** 15 — the provider clock normalizes deterministically, with no reference to "now". */
    public function test_occurred_at_is_normalized_deterministically(): void
    {
        $first = $this->adapter->normalizeEnvelope(FixtureEnvelopes::singleText())->messages[0];
        $second = $this->adapter->normalizeEnvelope(FixtureEnvelopes::singleText())->messages[0];

        $this->assertSame('2025-09-21T08:00:00+00:00', $first->occurredAt);
        $this->assertSame($first->occurredAt, $second->occurredAt, 'the same envelope must normalize identically');
    }

    /** 16 — the raw payload is the ITEM's slice, never the shared envelope. */
    public function test_raw_payload_is_the_item_level_slice(): void
    {
        $envelope = FixtureEnvelopes::threeMessages();
        $result = $this->adapter->normalizeEnvelope($envelope);

        foreach ($result->messages as $position => $message) {
            $this->assertArrayNotHasKey('events', $message->rawPayload, 'the envelope must not be copied onto an item');
            $this->assertSame($envelope['events'][$position], $message->rawPayload);
        }

        $payloads = array_map(fn (InboundConversationMessage $m): array => $m->rawPayload, $result->messages);
        $this->assertCount(3, array_unique(array_map('serialize', $payloads)), 'each sibling carries a distinct payload');
    }

    /** The basic Phase 3A contract stays satisfied, by delegation rather than a second parser. */
    public function test_the_phase_3a_normalize_contract_returns_the_same_messages(): void
    {
        $envelope = FixtureEnvelopes::malformedSibling();

        $this->assertEquals(
            $this->adapter->normalizeEnvelope($envelope)->messages,
            iterator_to_array((function () use ($envelope): iterable {
                yield from $this->adapter->normalize($envelope);
            })()),
        );
    }
}
