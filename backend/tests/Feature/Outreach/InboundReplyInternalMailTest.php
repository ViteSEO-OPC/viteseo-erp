<?php

namespace Tests\Feature\Outreach;

use App\Models\User;
use App\Modules\Crm\Events\InboundReplyReceived;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\NotificationDelivery;
use App\Modules\Crm\Notifications\InboundReplyMailNotification;
use App\Modules\Crm\Notifications\InboundReplyNotification;
use App\Modules\Crm\Services\Mail\Inbound\InboundMessage;
use App\Modules\Crm\Services\Outreach\InboundEmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Multi-Channel Internal Notifications. Pins the INTERNAL mail channel added on top of the RC2 inbound
 * fan-out: it is off by default, delivers at most once per (inbound email, channel), never fires for
 * unmatched/automated/unassigned inbound, and never disturbs the always-on in-app notification.
 *
 * Customer communication is out of scope by construction here — the internal alert writes no
 * `crm_emails` row, so the shared-mailbox architecture is untouched (asserted below).
 */
class InboundReplyInternalMailTest extends TestCase
{
    use RefreshDatabase;

    private function enableMailChannel(bool $enabled = true): void
    {
        config()->set('outreach.internal_notifications.mail', $enabled);
    }

    /**
     * @return array{0: User, 1: Lead, 2: Email}
     */
    private function scenario(array $userAttributes = []): array
    {
        $bd = User::factory()->create($userAttributes + ['email' => 'bruce@viteseo.test']);
        $lead = Lead::factory()->create([
            'assigned_to' => $bd->id,
            'email' => 'prospect@acme.test',
            'status' => 'awaiting-reply',
            'email_outreach' => 'sent',
        ]);
        $outbound = Email::create([
            'lead_id' => $lead->id,
            'user_id' => $bd->id,
            'direction' => 'outbound',
            'to_email' => $lead->email,
            'subject' => 'Our proposal',
            'body' => 'Proposal.',
            'provider' => 'smtp',
            'message_id' => 'out-'.uniqid().'@crm.test',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        return [$bd, $lead->refresh(), $outbound];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function reply(Lead $lead, Email $outbound, array $overrides = []): InboundMessage
    {
        return new InboundMessage(
            provider: 'gmail',
            mailbox: 'crm@company.test',
            fromEmail: $overrides['fromEmail'] ?? (string) $lead->email,
            fromName: 'Reply Person',
            toEmail: 'crm@company.test',
            subject: 'Re: Our proposal',
            bodyText: 'Yes, interested.',
            messageId: $overrides['messageId'] ?? 'in-'.uniqid().'@mail.gmail.com',
            inReplyTo: $outbound->message_id,
            references: [$outbound->message_id],
            providerMessageId: 'gmail-'.uniqid(),
            providerThreadId: 'thread-'.uniqid(),
            receivedAt: now()->toIso8601String(),
            headers: $overrides['headers'] ?? ['from' => (string) $lead->email],
        );
    }

    public function test_internal_mail_is_off_by_default(): void
    {
        Notification::fake();
        [$bd, $lead, $outbound] = $this->scenario();

        // Deliberately does NOT enable the flag — the shipped default must send no internal mail.
        app(InboundEmailService::class)->ingest($this->reply($lead, $outbound));

        Notification::assertNotSentTo($bd, InboundReplyMailNotification::class);
        $this->assertDatabaseCount('crm_notification_deliveries', 0);

        // The always-on in-app notification is unaffected by the flag.
        Notification::assertSentToTimes($bd, InboundReplyNotification::class, 1);
    }

    public function test_enabled_channel_emails_the_assigned_user_and_records_the_delivery(): void
    {
        Notification::fake();
        $this->enableMailChannel();
        [$bd, $lead, $outbound] = $this->scenario();

        $email = app(InboundEmailService::class)->ingest($this->reply($lead, $outbound));

        Notification::assertSentToTimes($bd, InboundReplyMailNotification::class, 1);
        // Both channels fire: in-app (RC2, unchanged) + internal mail (new).
        Notification::assertSentToTimes($bd, InboundReplyNotification::class, 1);

        $this->assertDatabaseHas('crm_notification_deliveries', [
            'email_id' => $email->id,
            'channel' => 'mail',
        ]);
    }

    public function test_delivery_row_records_the_recipient_in_meta(): void
    {
        Notification::fake();
        $this->enableMailChannel();
        [$bd, $lead, $outbound] = $this->scenario();

        $email = app(InboundEmailService::class)->ingest($this->reply($lead, $outbound));

        $delivery = NotificationDelivery::query()
            ->where('email_id', $email->id)->where('channel', 'mail')->sole();

        $this->assertSame($bd->id, $delivery->meta['notifiable_id']);
        $this->assertSame('bruce@viteseo.test', $delivery->meta['recipient']);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_redelivered_reply_emails_only_once(): void
    {
        Notification::fake();
        $this->enableMailChannel();
        [$bd, $lead, $outbound] = $this->scenario();
        $message = $this->reply($lead, $outbound);

        app(InboundEmailService::class)->ingest($message);
        app(InboundEmailService::class)->ingest($message); // same Message-ID redelivered by Pub/Sub

        Notification::assertSentToTimes($bd, InboundReplyMailNotification::class, 1);
        $this->assertDatabaseCount('crm_notification_deliveries', 1);
    }

    public function test_automated_traffic_does_not_email(): void
    {
        Notification::fake();
        $this->enableMailChannel();
        [$bd, $lead, $outbound] = $this->scenario();

        app(InboundEmailService::class)->ingest($this->reply($lead, $outbound, [
            'fromEmail' => 'mailer-daemon@mail.gmail.com',
            'headers' => ['auto-submitted' => 'auto-replied', 'from' => 'mailer-daemon@mail.gmail.com'],
        ]));

        Notification::assertNothingSent();
        $this->assertDatabaseCount('crm_notification_deliveries', 0);
    }

    public function test_unmatched_reply_does_not_email(): void
    {
        Notification::fake();
        $this->enableMailChannel();
        $this->scenario();

        $orphan = new InboundMessage(
            provider: 'gmail', mailbox: 'crm@company.test',
            fromEmail: 'stranger@nowhere.test', fromName: null, toEmail: 'crm@company.test',
            subject: 'Hello', bodyText: 'hi', messageId: 'in-'.uniqid().'@x',
            inReplyTo: null, references: [], headers: ['from' => 'stranger@nowhere.test'],
        );

        app(InboundEmailService::class)->ingest($orphan);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('crm_notification_deliveries', 0);
    }

    public function test_unassigned_lead_does_not_email(): void
    {
        Notification::fake();
        $this->enableMailChannel();
        [, $lead, $outbound] = $this->scenario();
        $lead->updateQuietly(['assigned_to' => null]);

        app(InboundEmailService::class)->ingest($this->reply($lead->refresh(), $outbound));

        Notification::assertNothingSent();
        $this->assertDatabaseCount('crm_notification_deliveries', 0);
    }

    public function test_deactivated_user_is_not_emailed(): void
    {
        Notification::fake();
        $this->enableMailChannel();
        [$bd, $lead, $outbound] = $this->scenario(['status' => 'inactive']);

        app(InboundEmailService::class)->ingest($this->reply($lead, $outbound));

        Notification::assertNotSentTo($bd, InboundReplyMailNotification::class);
        $this->assertDatabaseCount('crm_notification_deliveries', 0);

        // The in-app notification still lands — only the outbound staff EMAIL is withheld.
        Notification::assertSentToTimes($bd, InboundReplyNotification::class, 1);
    }

    public function test_internal_alert_creates_no_customer_email_row(): void
    {
        Notification::fake();
        $this->enableMailChannel();
        [, $lead, $outbound] = $this->scenario();

        app(InboundEmailService::class)->ingest($this->reply($lead, $outbound));

        // Exactly the seeded outbound + the one ingested inbound reply. The internal staff alert must
        // never appear in the lead's email history, be threadable, or count as outreach.
        $this->assertSame(2, Email::count());
        $this->assertSame(0, Email::where('to_email', 'bruce@viteseo.test')->count());
    }

    /**
     * The event MUST be dispatched after the persistence transaction has committed.
     *
     * This is load-bearing rather than incidental: every queue connection in config/queue.php sets
     * `after_commit => false`, so Laravel pushes a queued notification the instant it is dispatched. If
     * InboundReplyReceived ever fired while a transaction were still open, a subsequent rollback would
     * discard the inbound email AND its crm_notification_deliveries claim while the queued staff email
     * had already left — an orphaned notification announcing a reply that no longer exists.
     *
     * Asserted relative to a baseline because RefreshDatabase runs each test inside its own wrapping
     * transaction: an absolute level of 0 is unreachable here, so equality with the baseline is the real
     * signal that ingest() opened and closed its own transaction before dispatching.
     *
     * Also guards the production caller (SyncMailboxJob), which today calls ingest() with no
     * transaction of its own — wrapping it later would trip this.
     */
    public function test_event_is_dispatched_only_after_the_transaction_commits(): void
    {
        Notification::fake();
        $this->enableMailChannel();
        [, $lead, $outbound] = $this->scenario();

        $baseline = DB::transactionLevel();
        $levelAtDispatch = null;
        $committedEmails = null;

        Event::listen(InboundReplyReceived::class, function (InboundReplyReceived $event) use (&$levelAtDispatch, &$committedEmails) {
            $levelAtDispatch = DB::transactionLevel();
            // The inbound row must already be readable — i.e. genuinely written, not pending in a
            // still-open nested transaction that a later failure could unwind.
            $committedEmails = Email::whereKey($event->email->id)->count();
        });

        app(InboundEmailService::class)->ingest($this->reply($lead, $outbound));

        $this->assertNotNull($levelAtDispatch, 'InboundReplyReceived was never dispatched.');
        $this->assertSame(
            $baseline,
            $levelAtDispatch,
            'InboundReplyReceived fired inside an open transaction; a rollback could orphan the queued email.',
        );
        $this->assertSame(1, $committedEmails);
    }

    public function test_queued_so_smtp_never_blocks_ingestion(): void
    {
        // The notification MUST be queued: it is delivered from inside the queued inbound ingest job, so
        // a synchronous SMTP call there would let a slow/failing mail host delay inbound ingestion.
        $this->assertInstanceOf(
            ShouldQueue::class,
            new InboundReplyMailNotification(new Lead, new Email),
        );
    }
}
