<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Services\Inbound\Adapters\AdapterNormalizationResult;
use App\Modules\Crm\Services\Inbound\Adapters\RejectionCode;
use App\Modules\Crm\Services\Inbound\Meta\Adapters\MetaInstagramAdapter;
use App\Modules\Crm\Services\Inbound\Meta\Adapters\MetaMessengerAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Inbound\Meta\MetaEnvelopes;
use Tests\TestCase;

/**
 * Instagram DMs, which share Messenger's grammar and almost nothing else.
 *
 * The identities are IGSIDs against an Instagram business account rather than PSIDs against a Page,
 * and the two id spaces are not shared — so the thread prefix differs, and a test below pins that
 * the same numeric id on the two channels produces two different threads. Without that, an org
 * running both products could see an Instagram conversation merge into a Messenger one.
 */
class MetaInstagramNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private MetaInstagramAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new MetaInstagramAdapter;
    }

    private function normalize(array $items): AdapterNormalizationResult
    {
        return $this->adapter->normalizeEnvelope(['items' => $items]);
    }

    public function test_it_claims_only_meta_instagram_dm(): void
    {
        $this->assertTrue($this->adapter->supports('meta', 'instagram_dm'));
        $this->assertFalse($this->adapter->supports('meta', 'messenger'));
        $this->assertSame('meta_instagram', $this->adapter->name());
    }

    public function test_a_text_dm_normalizes(): void
    {
        $message = $this->normalize([MetaEnvelopes::igTextItem()])->messages[0];

        $this->assertSame('meta', $message->provider);
        $this->assertSame('instagram_dm', $message->channel);
        $this->assertSame('text', $message->messageType);
        $this->assertSame('Available pa po?', $message->text);
        $this->assertSame('ig.text1', $message->externalMessageId);
        $this->assertSame(MetaEnvelopes::IGSID, $message->senderExternalId);
        $this->assertSame(MetaEnvelopes::IG_A, $message->recipientExternalId);
    }

    public function test_media_normalizes(): void
    {
        $message = $this->normalize([MetaEnvelopes::igMediaItem()])->messages[0];

        $this->assertSame('image', $message->messageType);
        $this->assertSame('https://example.test/ig', $message->rawPayload['message']['attachments'][0]['payload']['url']);
    }

    /** A story reply is its own message type, which the Phase 3A allowlist already anticipated. */
    public function test_a_story_reply_normalizes_as_a_story_reply(): void
    {
        $message = $this->normalize([MetaEnvelopes::igStoryReplyItem()])->messages[0];

        $this->assertSame('story_reply', $message->messageType);
        $this->assertSame('Love this!', $message->text);
        $this->assertContains($message->messageType, (array) config('inbound.message_types'));
    }

    public function test_non_message_events_are_ignored(): void
    {
        $echo = MetaEnvelopes::igTextItem('ig.echo1');
        $echo['message']['is_echo'] = true;

        $read = ['sender' => ['id' => MetaEnvelopes::IGSID], 'recipient' => ['id' => MetaEnvelopes::IG_A], 'read' => ['mid' => 'ig.text1']];

        $result = $this->normalize([$echo, $read]);

        $this->assertSame(0, $result->messageCount());
        $this->assertSame(2, $result->ignoredCount());
    }

    public function test_a_malformed_child_does_not_discard_valid_siblings(): void
    {
        $result = $this->normalize([
            MetaEnvelopes::igTextItem('ig.a', 'first'),
            'not-an-array',
            MetaEnvelopes::igTextItem('ig.c', 'third'),
        ]);

        $this->assertSame(2, $result->messageCount());
        $this->assertSame(RejectionCode::MalformedEvent, $result->failures()[0]->code);
    }

    public function test_a_multi_message_delivery_normalizes_every_turn(): void
    {
        $result = $this->normalize([
            MetaEnvelopes::igTextItem('ig.1', 'one'),
            MetaEnvelopes::igTextItem('ig.2', 'two'),
        ]);

        $this->assertSame(2, $result->messageCount());
    }

    public function test_thread_identity_is_account_and_sender(): void
    {
        $message = $this->normalize([MetaEnvelopes::igTextItem()])->messages[0];

        $this->assertSame('igaccount:'.MetaEnvelopes::IG_A.':from:'.MetaEnvelopes::IGSID, $message->externalConversationId);
    }

    /**
     * The two channels must not collide even when the ids coincide.
     *
     * Page ids and Instagram account ids come from different namespaces, so nothing promises they
     * can never overlap — and if a collision ever produced one thread, two customers' conversations
     * would merge inside a single tenant.
     */
    public function test_the_same_ids_on_the_two_channels_are_two_threads(): void
    {
        $shared = ['sender' => ['id' => '555'], 'recipient' => ['id' => '999'], 'timestamp' => MetaEnvelopes::TS, 'message' => ['mid' => 'm', 'text' => 'hi']];

        $ig = $this->normalize([$shared])->messages[0];
        $messenger = (new MetaMessengerAdapter)->normalizeEnvelope(['items' => [$shared]])->messages[0];

        $this->assertNotSame($ig->externalConversationId, $messenger->externalConversationId);
    }

    public function test_an_item_without_a_message_id_is_still_accepted(): void
    {
        $item = MetaEnvelopes::igTextItem();
        unset($item['message']['mid']);

        $message = $this->normalize([$item])->messages[0];

        $this->assertNull($message->externalMessageId);
        $this->assertSame('text', $message->messageType);
    }
}
