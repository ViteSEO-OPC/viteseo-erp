<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Services\Inbound\Adapters\AdapterNormalizationResult;
use App\Modules\Crm\Services\Inbound\Adapters\RejectionCode;
use App\Modules\Crm\Services\Inbound\InboundConversationMessage;
use App\Modules\Crm\Services\Inbound\Meta\Adapters\MetaMessengerAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Inbound\Meta\MetaEnvelopes;
use Tests\TestCase;

/**
 * Turning Messenger traffic into conversation turns — and, mostly, deciding what not to.
 *
 * A real Messenger subscription is dominated by events that are not messages: delivery receipts,
 * read receipts, echoes of our own replies. Ingesting any of them would either duplicate our own
 * words as the customer's or fill a thread with noise, so the ignore list is as much the feature as
 * the normalization is.
 */
class MetaMessengerNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private MetaMessengerAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new MetaMessengerAdapter;
    }

    private function normalize(array $items): AdapterNormalizationResult
    {
        return $this->adapter->normalizeEnvelope(['items' => $items]);
    }

    public function test_it_claims_only_meta_messenger(): void
    {
        $this->assertTrue($this->adapter->supports('meta', 'messenger'));
        $this->assertFalse($this->adapter->supports('meta', 'instagram_dm'));
        $this->assertFalse($this->adapter->supports('meta', 'lead_ads'));
        $this->assertFalse($this->adapter->supports('fixture', 'messenger'));
        $this->assertSame('meta_messenger', $this->adapter->name());
    }

    public function test_a_text_message_normalizes(): void
    {
        $message = $this->normalize([MetaEnvelopes::textItem()])->messages[0];

        $this->assertSame('meta', $message->provider);
        $this->assertSame('messenger', $message->channel);
        $this->assertSame('text', $message->messageType);
        $this->assertSame('Magkano po ang SEO package?', $message->text);
        $this->assertSame('mid.text1', $message->externalMessageId);
        $this->assertSame(MetaEnvelopes::PSID, $message->senderExternalId);
        $this->assertSame(MetaEnvelopes::PAGE_A, $message->recipientExternalId);
        $this->assertTrue($message->isInbound());
    }

    public function test_each_attachment_kind_normalizes(): void
    {
        foreach (['image', 'video', 'audio', 'file', 'template'] as $kind) {
            $message = $this->normalize([MetaEnvelopes::attachmentItem($kind)])->messages[0];

            $this->assertSame($kind, $message->messageType);
            $this->assertNull($message->text);
        }
    }

    /** An attachment kind the contract cannot name is refused, never relabelled as something else. */
    public function test_an_unsupported_attachment_kind_is_rejected(): void
    {
        $result = $this->normalize([MetaEnvelopes::unsupportedAttachmentItem()]);

        $this->assertSame(0, $result->messageCount());
        $this->assertSame(RejectionCode::UnsupportedMessageType, $result->failures()[0]->code);
    }

    /**
     * ONE MID, ONE MESSAGE — including when the message carries text and two attachments.
     *
     * The type names the leading attachment and the complete item survives in rawPayload; splitting
     * it into three rows would mean three rows sharing one mid, and the mid is what makes replay
     * safe.
     */
    public function test_a_mixed_message_stays_one_turn_with_its_whole_item_preserved(): void
    {
        $result = $this->normalize([MetaEnvelopes::mixedItem()]);

        $this->assertSame(1, $result->messageCount());
        $message = $result->messages[0];

        $this->assertSame('image', $message->messageType, 'the first attachment names the type');
        $this->assertSame('Here are the photos', $message->text, 'the caption is not discarded');
        $this->assertCount(2, $message->rawPayload['message']['attachments'], 'nor is the second attachment');
    }

    public function test_a_postback_normalizes_as_a_customer_turn(): void
    {
        $message = $this->normalize([MetaEnvelopes::postbackItem()])->messages[0];

        $this->assertSame('postback', $message->messageType);
        $this->assertSame('Get a quote', $message->text);
        $this->assertNull($message->externalMessageId, 'Meta issues no mid for a postback');
        $this->assertSame('QUOTE_FLOW', $message->rawPayload['postback']['payload']);
    }

    /** Everything a busy Page produces that is not a customer turn. */
    public function test_non_message_traffic_is_ignored(): void
    {
        $items = [
            MetaEnvelopes::echoItem(),
            MetaEnvelopes::deliveryItem(),
            MetaEnvelopes::readItem(),
            MetaEnvelopes::reactionItem(),
            MetaEnvelopes::standbyItem(),
        ];

        $result = $this->normalize($items);

        $this->assertSame(0, $result->messageCount());
        $this->assertSame(5, $result->ignoredCount());
        $this->assertSame(0, $result->rejectionCount(), 'normal traffic is not an error');

        $reasons = array_map(fn ($r): ?string => $r->externalEventId, $result->ignored());
        $this->assertSame(['echo', 'delivery', 'read', 'reaction', 'standby'], $reasons);
    }

    /** An echo is our own reply; recording it would put our words in the customer's mouth. */
    public function test_an_echo_is_ignored_even_though_it_has_a_mid(): void
    {
        $result = $this->normalize([MetaEnvelopes::echoItem()]);

        $this->assertSame(0, $result->messageCount());
        $this->assertSame(1, $result->ignoredCount());
    }

    public function test_a_multi_message_envelope_normalizes_every_turn(): void
    {
        $result = $this->normalize([
            MetaEnvelopes::textItem('mid.a', 'first'),
            MetaEnvelopes::textItem('mid.b', 'second'),
            MetaEnvelopes::textItem('mid.c', 'third'),
        ]);

        $this->assertSame(
            ['mid.a', 'mid.b', 'mid.c'],
            array_map(fn (InboundConversationMessage $m): ?string => $m->externalMessageId, $result->messages),
        );
    }

    /** No mid falls through to the Phase 3A derived key, so each item needs its own slice. */
    public function test_midless_messages_keep_distinct_item_payloads(): void
    {
        $result = $this->normalize([
            MetaEnvelopes::midlessItem('una'),
            MetaEnvelopes::midlessItem('pangalawa'),
        ]);

        $this->assertSame(2, $result->messageCount());

        foreach ($result->messages as $message) {
            $this->assertNull($message->externalMessageId);
            $this->assertArrayNotHasKey('entry', $message->rawPayload);
        }

        $this->assertNotSame($result->messages[0]->rawPayload, $result->messages[1]->rawPayload);
    }

    public function test_an_item_without_both_identities_is_rejected(): void
    {
        $result = $this->normalize([MetaEnvelopes::identitylessItem()]);

        $this->assertSame(RejectionCode::MissingProviderIdentity, $result->failures()[0]->code);
    }

    /** Thread identity is (Page, sender) — stable, and not shared across an org's Pages. */
    public function test_thread_identity_is_stable_and_page_specific(): void
    {
        $first = $this->normalize([MetaEnvelopes::textItem('mid.1')])->messages[0];
        $again = $this->normalize([MetaEnvelopes::textItem('mid.2')])->messages[0];

        $this->assertSame($first->externalConversationId, $again->externalConversationId);
        $this->assertSame('fbpage:'.MetaEnvelopes::PAGE_A.':from:'.MetaEnvelopes::PSID, $first->externalConversationId);

        // The same person writing to a second Page is a different conversation.
        $otherPage = MetaEnvelopes::textItem('mid.3');
        $otherPage['recipient']['id'] = MetaEnvelopes::PAGE_B;

        $this->assertNotSame(
            $first->externalConversationId,
            $this->normalize([$otherPage])->messages[0]->externalConversationId,
        );
    }

    /** No tenant id anywhere in the identifier: it has to mean the same thing before tenancy is known. */
    public function test_thread_identity_contains_no_org_reference(): void
    {
        $id = $this->normalize([MetaEnvelopes::textItem()])->messages[0]->externalConversationId;

        $this->assertStringNotContainsString('org', $id);
        $this->assertStringNotContainsString((string) $this->tenant()->id, str_replace([MetaEnvelopes::PAGE_A, MetaEnvelopes::PSID], '', $id));
    }

    /** Meta sends milliseconds; the same item must always normalize to the same instant. */
    public function test_the_timestamp_normalizes_deterministically(): void
    {
        $first = $this->normalize([MetaEnvelopes::textItem()])->messages[0];
        $second = $this->normalize([MetaEnvelopes::textItem()])->messages[0];

        $this->assertSame('2025-09-21T08:00:00+00:00', $first->occurredAt);
        $this->assertSame($first->occurredAt, $second->occurredAt);
    }

    /** The Phase 3A contract is satisfied by delegation, not by a second parser. */
    public function test_the_basic_contract_returns_the_same_messages(): void
    {
        $items = [MetaEnvelopes::textItem(), MetaEnvelopes::deliveryItem()];

        $this->assertEquals(
            $this->normalize($items)->messages,
            iterator_to_array((function () use ($items): iterable {
                yield from $this->adapter->normalize(['items' => $items]);
            })()),
        );
    }
}
