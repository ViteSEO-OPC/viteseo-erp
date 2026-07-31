<?php

namespace Tests\Feature\Outreach;

use App\Modules\Crm\Jobs\SyncMailboxJob;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\MailboxSync;
use App\Modules\Crm\Services\Mail\Contracts\MailboxSyncProvider;
use App\Modules\Crm\Services\Mail\Inbound\InboundMessage;
use App\Modules\Crm\Services\Mail\Inbound\MailboxFetchResult;
use App\Modules\Crm\Services\Outreach\InboundEmailService;
use App\Modules\Crm\Services\Outreach\InboundReplyProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Item #1 (R7) — poison-message isolation. A single message that fails to ingest must never abort the
 * batch or block the cursor advance; otherwise the unchanged cursor is re-fetched on every poll and the
 * whole mailbox stalls forever. Pinned at the ingest loop in SyncMailboxJob, which is now the only one
 * (the push job and the hourly reconcile job it also covered were removed with the Pub/Sub trigger).
 *
 * The provider-level hydration isolation (GmailMailboxSync::fetchSince, per-message get+parse) mirrors this
 * ingest-level isolation but is not unit-tested here: GmailMailboxSync builds its Gmail client internally
 * (not injectable), consistent with the suite's existing strategy of faking the MailboxSyncProvider wholesale.
 */
class InboundPoisonIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const POISON = 'poison@x';

    /** Fixed-message fake provider. */
    private function fakeProvider(array $messages, ?string $advancedCursor): MailboxSyncProvider
    {
        return new class($messages, $advancedCursor) implements MailboxSyncProvider
        {
            /** @param list<InboundMessage> $messages */
            public function __construct(private array $messages, private ?string $advancedCursor) {}

            public function fetchSince(string $mailbox, ?string $cursor): MailboxFetchResult
            {
                return new MailboxFetchResult($this->messages, $this->advancedCursor);
            }
        };
    }

    /** Real ingest for every message EXCEPT the poison id, which throws — simulating a malformed message. */
    private function poisonAwareInbound(): InboundEmailService
    {
        return new class(app(InboundReplyProcessor::class)) extends InboundEmailService
        {
            public function ingest(InboundMessage $message): ?Email
            {
                if ($message->messageId === 'poison@x') {
                    throw new \RuntimeException('malformed message body');
                }

                return parent::ingest($message);
            }
        };
    }

    private function message(string $id): InboundMessage
    {
        return new InboundMessage(
            provider: 'gmail', mailbox: 'crm@company.test',
            fromEmail: 'stranger@nowhere.test', fromName: null, toEmail: 'crm@company.test',
            subject: 'Hi', bodyText: 'body', messageId: $id,
            inReplyTo: null, references: [], headers: ['from' => 'stranger@nowhere.test'],
        );
    }

    public function test_job_skips_a_poison_message_and_still_advances_the_cursor(): void
    {
        Notification::fake();
        $messages = [$this->message('good-1@x'), $this->message(self::POISON), $this->message('good-2@x')];
        $provider = $this->fakeProvider($messages, '205');

        (new SyncMailboxJob('crm@company.test'))->handle($provider, $this->poisonAwareInbound());

        // The two good messages persisted; the poison one was skipped, not fatal.
        $this->assertSame(2, Email::where('direction', 'inbound')->count());
        $this->assertSame(0, Email::where('message_id', self::POISON)->count());

        // Cursor advanced despite the poison — the mailbox is NOT stalled.
        $sync = MailboxSync::where('email', 'crm@company.test')->first();
        $this->assertNotNull($sync);
        $this->assertSame('205', $sync->sync_cursor);
        $this->assertNotNull($sync->last_synced_at);
    }

    public function test_job_advances_the_cursor_even_when_every_message_poisons(): void
    {
        Notification::fake();
        $provider = $this->fakeProvider([$this->message(self::POISON)], '208');

        (new SyncMailboxJob('crm@company.test'))->handle($provider, $this->poisonAwareInbound());

        $this->assertSame(0, Email::where('direction', 'inbound')->count());
        // The whole batch failed to ingest, but the cursor STILL advances so the poison is never re-fetched.
        $this->assertSame('208', MailboxSync::where('email', 'crm@company.test')->first()->sync_cursor);
    }

    /** The same isolation for a mailbox that already has a cursor (the steady-state poll). */
    public function test_poll_skips_a_poison_message_and_advances_an_existing_mailbox_cursor(): void
    {
        Notification::fake();
        $sync = MailboxSync::create([
            'email' => 'crm@company.test', 'provider' => 'gmail',
            'sync_cursor' => '100', 'status' => 'active',
        ]);
        $provider = $this->fakeProvider([$this->message(self::POISON), $this->message('good-3@x')], '211');

        (new SyncMailboxJob('crm@company.test'))->handle($provider, $this->poisonAwareInbound());

        // Good message ingested, poison skipped, and this mailbox's cursor advanced (no re-stall).
        $this->assertSame(1, Email::where('direction', 'inbound')->count());
        $this->assertSame('211', $sync->fresh()->sync_cursor);
    }
}
