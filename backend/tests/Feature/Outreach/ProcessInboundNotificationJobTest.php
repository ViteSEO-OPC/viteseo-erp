<?php

namespace Tests\Feature\Outreach;

use App\Modules\Crm\Jobs\ProcessInboundNotificationJob;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\MailboxSync;
use App\Modules\Crm\Services\Mail\Contracts\MailboxSyncProvider;
use App\Modules\Crm\Services\Mail\Inbound\InboundMessage;
use App\Modules\Crm\Services\Mail\Inbound\MailboxFetchResult;
use App\Modules\Crm\Services\Mail\Inbound\NotificationCursor;
use App\Modules\Crm\Services\Mail\Inbound\WatchRegistration;
use App\Modules\Crm\Services\Outreach\InboundEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ProcessInboundNotificationJob: the async worker behind the webhook. Verifies the cursor lifecycle
 * (resolve → fetchSince → advance), retry configuration, and — since Pub/Sub is at-least-once — that a
 * re-run (retry / reconcile) never double-persists thanks to idempotent ingest.
 */
class ProcessInboundNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<InboundMessage>  $messages
     */
    private function fakeProvider(string $mailbox, array $messages, ?string $advancedCursor): MailboxSyncProvider
    {
        return new class($mailbox, $messages, $advancedCursor) implements MailboxSyncProvider
        {
            /** @param list<InboundMessage> $messages */
            public function __construct(
                private string $mailbox,
                private array $messages,
                private ?string $advancedCursor,
            ) {}

            public function watch(string $mailbox): WatchRegistration
            {
                return new WatchRegistration('1', null);
            }

            public function unwatch(string $mailbox): void {}

            public function fetchSince(string $mailbox, ?string $cursor): MailboxFetchResult
            {
                return new MailboxFetchResult($this->messages, $this->advancedCursor);
            }

            /** @param array<string, mixed> $payload */
            public function parseNotification(array $payload): NotificationCursor
            {
                return new NotificationCursor($this->mailbox, '100');
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

    public function test_handle_ingests_messages_and_advances_the_cursor(): void
    {
        Notification::fake();
        $provider = $this->fakeProvider('crm@company.test', [$this->message('j-1@x'), $this->message('j-2@x')], '205');

        (new ProcessInboundNotificationJob(['message' => []]))->handle($provider, app(InboundEmailService::class));

        $this->assertSame(2, Email::where('direction', 'inbound')->count());
        $sync = MailboxSync::where('email', 'crm@company.test')->first();
        $this->assertNotNull($sync);
        $this->assertSame('205', $sync->sync_cursor);
        $this->assertNotNull($sync->last_synced_at);
    }

    public function test_notification_without_a_mailbox_is_a_safe_noop(): void
    {
        Notification::fake();
        $provider = $this->fakeProvider('', [$this->message('j-3@x')], '1'); // empty mailbox

        (new ProcessInboundNotificationJob(['message' => []]))->handle($provider, app(InboundEmailService::class));

        $this->assertSame(0, Email::where('direction', 'inbound')->count());
        $this->assertSame(0, MailboxSync::count());
    }

    public function test_rerun_is_idempotent_so_retries_never_double_persist(): void
    {
        Notification::fake();
        $provider = $this->fakeProvider('crm@company.test', [$this->message('j-4@x')], '210');
        $job = new ProcessInboundNotificationJob(['message' => []]);

        $job->handle($provider, app(InboundEmailService::class));
        $job->handle($provider, app(InboundEmailService::class)); // simulate a retry / reconcile overlap

        $this->assertSame(1, Email::where('direction', 'inbound')->count());
    }

    public function test_retry_configuration_is_production_grade(): void
    {
        $job = new ProcessInboundNotificationJob(['message' => []]);

        $this->assertSame(5, $job->tries);
        $this->assertSame(30, $job->backoff);
    }
}
