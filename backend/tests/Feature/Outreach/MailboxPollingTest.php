<?php

namespace Tests\Feature\Outreach;

use App\Modules\Crm\Jobs\SyncMailboxJob;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\MailboxSync;
use App\Modules\Crm\Services\Mail\Contracts\MailboxSyncProvider;
use App\Modules\Crm\Services\Mail\Inbound\InboundMessage;
use App\Modules\Crm\Services\Mail\Inbound\MailboxFetchResult;
use App\Modules\Crm\Services\Mail\MailboxSyncManager;
use App\Modules\Crm\Services\Outreach\InboundEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The scheduler-based inbound trigger: `outreach:sync` fanning out one SyncMailboxJob per mailbox.
 *
 * These pin the properties the Pub/Sub push used to provide structurally and that polling has to
 * provide deliberately — which mailboxes get picked up, that a repeated poll cannot duplicate mail,
 * and that one mailbox's failure does not take the others down.
 */
class MailboxPollingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The job resolves its provider through the manager, so a fake has to be registered as a driver
     * rather than handed over directly. Registered under `gmail` because that is what these
     * mailboxes' rows say — which is precisely the resolution being exercised.
     */
    private function managerFor(MailboxSyncProvider $provider): MailboxSyncManager
    {
        return (new MailboxSyncManager($this->app))->extend('gmail', fn () => $provider);
    }

    /** A provider that returns a fixed batch and cursor, and counts how often it was asked. */
    private function fakeProvider(array $messages, ?string $cursor): MailboxSyncProvider
    {
        return new class($messages, $cursor) implements MailboxSyncProvider
        {
            public int $calls = 0;

            /** @param list<InboundMessage> $messages */
            public function __construct(private array $messages, private ?string $cursor) {}

            public function fetchSince(string $mailbox, ?string $cursor): MailboxFetchResult
            {
                $this->calls++;

                return new MailboxFetchResult($this->messages, $this->cursor);
            }
        };
    }

    private function message(string $id, string $mailbox = 'crm@company.test'): InboundMessage
    {
        return new InboundMessage(
            provider: 'gmail', mailbox: $mailbox,
            fromEmail: 'stranger@nowhere.test', fromName: null, toEmail: $mailbox,
            subject: 'Hi', bodyText: 'body', messageId: $id,
            inReplyTo: null, references: [], headers: ['from' => 'stranger@nowhere.test'],
        );
    }

    // ── Fan-out: which mailboxes get polled ─────────────────────────────────────────────────────

    public function test_the_command_dispatches_one_job_per_syncable_mailbox(): void
    {
        Queue::fake();
        MailboxSync::create(['email' => 'a@company.test', 'provider' => 'gmail', 'status' => 'active']);
        MailboxSync::create(['email' => 'b@company.test', 'provider' => 'gmail', 'status' => 'active']);

        $this->artisan('outreach:sync')->assertSuccessful();

        Queue::assertPushed(SyncMailboxJob::class, 2);
        foreach (['a@company.test', 'b@company.test'] as $mailbox) {
            Queue::assertPushed(
                SyncMailboxJob::class,
                fn (SyncMailboxJob $job) => $job->mailbox === $mailbox,
            );
        }
    }

    /**
     * `error` is transient — set whenever Gmail was unreachable — and nothing else ever clears it
     * back to `active`. Excluding it from the poll would mean one outage silently retired a mailbox
     * for good. Only an explicit `paused` opts out.
     */
    public function test_an_errored_mailbox_is_retried_but_a_paused_one_is_not(): void
    {
        Queue::fake();
        MailboxSync::create(['email' => 'broken@company.test', 'provider' => 'gmail', 'status' => 'error']);
        MailboxSync::create(['email' => 'paused@company.test', 'provider' => 'gmail', 'status' => 'paused']);

        $this->artisan('outreach:sync')->assertSuccessful();

        Queue::assertPushed(SyncMailboxJob::class, 1);
        Queue::assertPushed(
            SyncMailboxJob::class,
            fn (SyncMailboxJob $job) => $job->mailbox === 'broken@company.test',
        );
    }

    /** A fresh deployment has no rows yet; the configured mailbox makes inbound self-starting. */
    public function test_an_empty_table_falls_back_to_the_configured_mailbox(): void
    {
        Queue::fake();
        config(['outreach.mailbox' => 'configured@company.test']);

        $this->artisan('outreach:sync')->assertSuccessful();

        Queue::assertPushed(
            SyncMailboxJob::class,
            fn (SyncMailboxJob $job) => $job->mailbox === 'configured@company.test',
        );
    }

    /** No mailboxes anywhere is a normal state, not a failure the scheduler should report every 5 min. */
    public function test_no_mailboxes_configured_succeeds_quietly_without_dispatching(): void
    {
        Queue::fake();
        config(['outreach.mailbox' => null]);

        $this->artisan('outreach:sync')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_an_explicit_mailbox_option_targets_only_that_mailbox(): void
    {
        Queue::fake();
        MailboxSync::create(['email' => 'a@company.test', 'provider' => 'gmail', 'status' => 'active']);

        $this->artisan('outreach:sync', ['--mailbox' => 'onboarding@company.test'])->assertSuccessful();

        Queue::assertPushed(SyncMailboxJob::class, 1);
        Queue::assertPushed(
            SyncMailboxJob::class,
            fn (SyncMailboxJob $job) => $job->mailbox === 'onboarding@company.test',
        );
    }

    // ── No duplicate emails ─────────────────────────────────────────────────────────────────────

    /**
     * THE property that makes a repeating poll safe. Two runs handed the same batch — the situation a
     * five-minute cadence creates whenever a sync overlaps, a job retries, or the provider re-offers
     * messages after a bootstrap — must leave exactly one Email row per message.
     */
    public function test_polling_the_same_batch_twice_creates_no_duplicate_emails(): void
    {
        Notification::fake();
        $messages = [$this->message('dup-1@x'), $this->message('dup-2@x')];
        $provider = $this->fakeProvider($messages, '300');
        $inbound = app(InboundEmailService::class);

        (new SyncMailboxJob('crm@company.test'))->handle($this->managerFor($provider), $inbound);
        (new SyncMailboxJob('crm@company.test'))->handle($this->managerFor($provider), $inbound);

        $this->assertSame(2, $provider->calls, 'Both polls must really have run.');
        $this->assertSame(2, Email::where('direction', 'inbound')->count());
    }

    /** Two mailboxes are independent: separate rows, separate cursors, no cross-contamination. */
    public function test_multiple_mailboxes_sync_independently(): void
    {
        Notification::fake();
        $inbound = app(InboundEmailService::class);

        (new SyncMailboxJob('one@company.test'))
            ->handle($this->managerFor($this->fakeProvider([$this->message('one-1@x', 'one@company.test')], '111')), $inbound);
        (new SyncMailboxJob('two@company.test'))
            ->handle($this->managerFor($this->fakeProvider([$this->message('two-1@x', 'two@company.test')], '222')), $inbound);

        $this->assertSame('111', MailboxSync::where('email', 'one@company.test')->first()->sync_cursor);
        $this->assertSame('222', MailboxSync::where('email', 'two@company.test')->first()->sync_cursor);
        $this->assertSame(2, Email::where('direction', 'inbound')->count());
    }

    // ── Cursor + status bookkeeping ─────────────────────────────────────────────────────────────

    public function test_an_unknown_mailbox_is_registered_on_first_poll_with_a_null_cursor(): void
    {
        Notification::fake();

        (new SyncMailboxJob('new@company.test'))->handle($this->managerFor($this->fakeProvider([], '900')), app(InboundEmailService::class));

        $sync = MailboxSync::where('email', 'new@company.test')->first();
        $this->assertNotNull($sync, 'The poll must register a mailbox it has never seen.');
        $this->assertSame('900', $sync->sync_cursor);
        $this->assertSame('active', $sync->status);
        $this->assertNotNull($sync->last_synced_at);
    }

    /** A null returned cursor means "nothing to advance to" — the stored one must survive. */
    public function test_a_null_returned_cursor_leaves_the_stored_cursor_intact(): void
    {
        Notification::fake();
        MailboxSync::create([
            'email' => 'crm@company.test', 'provider' => 'gmail',
            'sync_cursor' => '500', 'status' => 'active',
        ]);

        (new SyncMailboxJob('crm@company.test'))->handle($this->managerFor($this->fakeProvider([], null)), app(InboundEmailService::class));

        $this->assertSame('500', MailboxSync::where('email', 'crm@company.test')->first()->sync_cursor);
    }

    /**
     * A transport failure must mark the mailbox and rethrow, so the queue's retry/backoff handles it.
     * Swallowing it would report success and silently stop syncing that mailbox.
     */
    public function test_a_provider_failure_marks_the_mailbox_and_rethrows_for_the_queue_to_retry(): void
    {
        $failing = new class implements MailboxSyncProvider
        {
            public function fetchSince(string $mailbox, ?string $cursor): MailboxFetchResult
            {
                throw new \RuntimeException('gmail unreachable');
            }
        };

        try {
            (new SyncMailboxJob('crm@company.test'))->handle($this->managerFor($failing), app(InboundEmailService::class));
            $this->fail('The job must rethrow so the queue retries it.');
        } catch (\RuntimeException $e) {
            $this->assertSame('gmail unreachable', $e->getMessage());
        }

        $this->assertSame('error', MailboxSync::where('email', 'crm@company.test')->first()->status);
    }

    /** …and a later successful poll clears that error, so `status` reflects the present. */
    public function test_a_successful_poll_clears_a_previous_error_status(): void
    {
        Notification::fake();
        MailboxSync::create([
            'email' => 'crm@company.test', 'provider' => 'gmail',
            'sync_cursor' => '10', 'status' => 'error',
        ]);

        (new SyncMailboxJob('crm@company.test'))->handle($this->managerFor($this->fakeProvider([], '11')), app(InboundEmailService::class));

        $this->assertSame('active', MailboxSync::where('email', 'crm@company.test')->first()->status);
    }

    /** One in-flight sync per mailbox — the guard against a slow sync overlapping the next tick. */
    public function test_the_job_is_unique_per_mailbox(): void
    {
        $this->assertSame('a@company.test', (new SyncMailboxJob('a@company.test'))->uniqueId());
        $this->assertNotSame(
            (new SyncMailboxJob('a@company.test'))->uniqueId(),
            (new SyncMailboxJob('b@company.test'))->uniqueId(),
        );
    }
}
