<?php

namespace Tests\Feature\Outreach;

use App\Modules\Crm\Services\Mail\Contracts\GoogleCredentialSource;
use App\Modules\Crm\Services\Mail\Inbound\InboundMessage;
use App\Modules\Crm\Services\Mail\Providers\GmailMailboxSync;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\History;
use Google\Service\Gmail\HistoryMessageAdded;
use Google\Service\Gmail\ListHistoryResponse;
use Google\Service\Gmail\Message as GmailMessage;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartBody;
use Google\Service\Gmail\MessagePartHeader;
use Tests\TestCase;

/**
 * Turning a raw Gmail message into an InboundMessage — the step every inbound reply passes through,
 * and the one place the existing suite never actually executed.
 *
 * WHY THIS FILE EXISTS. `toInboundMessage()` called `Carbon::parse()` with no `use` import, so PHP
 * resolved it to `App\Modules\Crm\Services\Mail\Providers\Carbon` and threw. Every real message
 * carries a `Date:` header, so EVERY real message threw — and because `hydrateAll()` catches per
 * message so one malformed mail cannot poison a batch, the failure was silent: `status` stayed
 * `active`, the cursor advanced past the lost message, and the job reported success with
 * `ingested: 0`. Confirmed live on 2026-08-03 against business@viteseo.ph.
 *
 * The suite missed it because the other Gmail tests stub `hydrate()` to isolate the delta/recovery
 * orchestration — a reasonable seam that happened to be exactly where the bug lived. So these tests
 * do the opposite: they stub only the raw Gmail transport and run the real parsing.
 */
class GmailMessageHydrationTest extends TestCase
{
    /** A Gmail message shaped like a real reply: multipart, RFC-2822 headers, base64url body. */
    private function gmailMessage(array $headers, string $body = 'Thanks, that works for me.'): GmailMessage
    {
        $partBody = new MessagePartBody;
        $partBody->setData(rtrim(strtr(base64_encode($body), '+/', '-_'), '='));

        $part = new MessagePart;
        $part->setMimeType('text/plain');
        $part->setBody($partBody);
        $part->setHeaders(array_map(function (string $name) use ($headers): MessagePartHeader {
            $header = new MessagePartHeader;
            $header->setName($name);
            $header->setValue($headers[$name]);

            return $header;
        }, array_keys($headers)));

        $message = new GmailMessage;
        $message->setId('19fc58ed75edf968');
        $message->setThreadId('19fc58ed75edf900');
        $message->setPayload($part);

        return $message;
    }

    /** The real provider with only the Gmail transport stubbed — parsing runs for real. */
    private function hydrate(GmailMessage $message, string $mailbox = 'business@viteseo.ph'): InboundMessage
    {
        $credentials = new class implements GoogleCredentialSource
        {
            public function authorize(GoogleClient $client, string $mailbox): void {}
        };

        $sync = new class($credentials) extends GmailMailboxSync
        {
            /** Exposes the protected seam so the private parsing below it is genuinely executed. */
            public function hydratePublicly(Gmail $gmail, string $mailbox, string $id): InboundMessage
            {
                return $this->hydrate($gmail, $mailbox, $id);
            }
        };

        $gmail = new Gmail(new GoogleClient);
        // The SDK exposes its resources as public properties; swapping one is the narrowest possible
        // stub — everything from getPayload() inward is the real code path.
        $gmail->users_messages = new class($message)
        {
            public function __construct(private readonly GmailMessage $message) {}

            public function get(string $userId, string $id, array $optParams = []): GmailMessage
            {
                return $this->message;
            }
        };

        return $sync->hydratePublicly($gmail, $mailbox, '19fc58ed75edf968');
    }

    /**
     * THE regression. Before the fix this threw
     * `Error: Class "App\Modules\Crm\Services\Mail\Providers\Carbon" not found`
     * for any message carrying a Date header — i.e. all of them.
     */
    public function test_a_message_with_a_date_header_hydrates(): void
    {
        $message = $this->hydrate($this->gmailMessage([
            'From' => 'Jane Prospect <jane@prospect.test>',
            'To' => 'business@viteseo.ph',
            'Subject' => 'Re: Proposal',
            'Date' => 'Mon, 3 Aug 2026 10:15:00 +0800',
            'Message-ID' => '<reply-1@prospect.test>',
        ]));

        $this->assertInstanceOf(InboundMessage::class, $message);
        $this->assertSame('jane@prospect.test', $message->fromEmail);
        $this->assertSame('Jane Prospect', $message->fromName);
        $this->assertSame('Re: Proposal', $message->subject);
        $this->assertSame('Thanks, that works for me.', $message->bodyText);
        $this->assertSame('19fc58ed75edf968', $message->providerMessageId);
    }

    /** The Date header is what receivedAt is FOR — parsed, normalised to ISO-8601, not dropped. */
    public function test_the_date_header_becomes_an_iso8601_received_at(): void
    {
        $message = $this->hydrate($this->gmailMessage([
            'From' => 'jane@prospect.test',
            'Date' => 'Mon, 3 Aug 2026 10:15:00 +0800',
        ]));

        $this->assertNotNull($message->receivedAt);
        $this->assertSame('2026-08-03T10:15:00+08:00', $message->receivedAt);
    }

    /** Timezone is preserved from the header rather than silently reinterpreted as local time. */
    public function test_a_utc_date_header_keeps_its_offset(): void
    {
        $message = $this->hydrate($this->gmailMessage([
            'From' => 'jane@prospect.test',
            'Date' => 'Mon, 3 Aug 2026 02:15:00 +0000',
        ]));

        $this->assertSame('2026-08-03T02:15:00+00:00', $message->receivedAt);
    }

    /** The null path still works — receivedAt is optional, and a missing header is not a failure. */
    public function test_a_message_without_a_date_header_still_hydrates(): void
    {
        $message = $this->hydrate($this->gmailMessage([
            'From' => 'jane@prospect.test',
            'Subject' => 'No date',
        ]));

        $this->assertNull($message->receivedAt);
        $this->assertSame('No date', $message->subject);
    }

    /** Threading headers survive hydration — they are how a reply finds its lead. */
    public function test_threading_headers_are_carried_through(): void
    {
        $message = $this->hydrate($this->gmailMessage([
            'From' => 'jane@prospect.test',
            'Date' => 'Mon, 3 Aug 2026 10:15:00 +0800',
            'Message-ID' => '<reply-1@prospect.test>',
            'In-Reply-To' => '<outbound-9@viteseo.ph>',
            'References' => '<outbound-9@viteseo.ph> <earlier-3@viteseo.ph>',
        ]));

        $this->assertSame('reply-1@prospect.test', $message->messageId);
        $this->assertSame('outbound-9@viteseo.ph', $message->inReplyTo);
        $this->assertSame(
            ['outbound-9@viteseo.ph', 'earlier-3@viteseo.ph'],
            $message->references,
        );
    }

    /**
     * The silence itself, through the real delta path.
     *
     * `hydrateAll()` swallows per-message failures on purpose so one malformed mail cannot poison a
     * batch — which is exactly why the Carbon bug produced a healthy-looking sync that ingested
     * nothing. This drives `fetchSince()` with only the history transport stubbed, so a message that
     * fails to hydrate would come back as an empty result and a silently advanced cursor, precisely
     * as it did in production. Asserted at the boundary the job actually consumes.
     */
    public function test_a_dated_message_survives_the_delta_sync_instead_of_vanishing(): void
    {
        $credentials = new class implements GoogleCredentialSource
        {
            public function authorize(GoogleClient $client, string $mailbox): void {}
        };

        $sync = new class($credentials) extends GmailMailboxSync
        {
            public ?GmailMessage $stubbed = null;

            protected function gmail(string $mailbox): Gmail
            {
                $gmail = new Gmail(new GoogleClient);
                $gmail->users_messages = new class($this->stubbed)
                {
                    public function __construct(private readonly ?GmailMessage $message) {}

                    public function get(string $userId, string $id, array $optParams = []): GmailMessage
                    {
                        return $this->message;
                    }
                };

                return $gmail;
            }

            protected function fetchHistoryPage(Gmail $gmail, string $startHistoryId, ?string $pageToken): ListHistoryResponse
            {
                $message = new GmailMessage;
                $message->setId('19fc58ed75edf968');
                $added = new HistoryMessageAdded;
                $added->setMessage($message);
                $record = new History;
                $record->setId('27580');
                $record->setMessagesAdded([$added]);
                $response = new ListHistoryResponse;
                $response->setHistory([$record]);

                return $response; // nextPageToken null → single page
            }
        };

        $sync->stubbed = $this->gmailMessage([
            'From' => 'jane@prospect.test',
            'Date' => 'Mon, 3 Aug 2026 10:15:00 +0800',
            'Subject' => 'Re: Proposal',
        ]);

        $result = $sync->fetchSince('business@viteseo.ph', '27576');

        $this->assertCount(1, $result->messages, 'a well-formed dated message must not be dropped mid-sync');
        $this->assertSame('Re: Proposal', $result->messages[0]->subject);
        $this->assertSame('27580', $result->cursor);
    }
}
