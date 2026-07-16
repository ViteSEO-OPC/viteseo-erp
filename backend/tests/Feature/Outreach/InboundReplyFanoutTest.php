<?php

namespace Tests\Feature\Outreach;

use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Models\User;
use App\Modules\Crm\Notifications\InboundReplyNotification;
use App\Modules\Crm\Services\Mail\Inbound\InboundMessage;
use App\Modules\Crm\Services\Outreach\InboundEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * RC2 inbound reply fan-out. Pins the post-ingest contract: a matched, human reply completes the open
 * follow-up (Planner-owned), advances the COMMUNICATION lifecycle (`email_outreach → replied`) WITHOUT
 * touching the SALES lifecycle (`leads.status`), refreshes the Lead Score through the existing engine,
 * and notifies the assigned BD — each exactly once, never on duplicate/automated/unmatched inbound.
 */
class InboundReplyFanoutTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * @return array{0: User, 1: Lead, 2: Email, 3: PlannerEvent}
     */
    private function scenario(): array
    {
        $bd = User::factory()->create();
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
        $followUp = PlannerEvent::create([
            'event_type' => 'follow-up',
            'title' => 'Follow-up',
            'status' => 'todo',
            'source' => 'outreach',
            'lead_id' => $lead->id,
            'assigned_to' => $bd->id,
            'created_by' => $bd->id,
        ]);

        return [$bd, $lead->refresh(), $outbound, $followUp];
    }

    public function test_matched_human_reply_runs_the_full_fanout_once(): void
    {
        Notification::fake();
        [$bd, $lead, $outbound, $followUp] = $this->scenario();
        $statusBefore = $lead->status;

        app(InboundEmailService::class)->ingest($this->reply($lead, $outbound));
        $lead->refresh();

        // Communication lifecycle advanced; SALES lifecycle untouched (the frozen separation).
        $this->assertSame('replied', $lead->email_outreach);
        $this->assertSame($statusBefore, $lead->status);
        $this->assertNotNull($lead->last_activity);

        // Activities: the received reply + the follow-up completion.
        $this->assertDatabaseHas('activities', ['event' => 'email.received', 'subject_id' => $lead->id]);
        $this->assertDatabaseHas('activities', ['event' => 'planner-event.completed', 'subject_id' => $lead->id]);
        $this->assertSame('completed', $followUp->refresh()->status);

        // Assigned BD notified exactly once.
        Notification::assertSentToTimes($bd, InboundReplyNotification::class, 1);
    }

    public function test_lead_status_is_never_auto_mutated(): void
    {
        Notification::fake();
        [, $lead, $outbound] = $this->scenario();

        app(InboundEmailService::class)->ingest($this->reply($lead, $outbound));

        $this->assertSame('awaiting-reply', $lead->refresh()->status);
    }

    public function test_duplicate_delivery_is_idempotent(): void
    {
        Notification::fake();
        [$bd, $lead, $outbound] = $this->scenario();
        $message = $this->reply($lead, $outbound);

        app(InboundEmailService::class)->ingest($message);
        app(InboundEmailService::class)->ingest($message); // same Message-ID redelivered

        $this->assertSame(1, Email::where('lead_id', $lead->id)->where('direction', 'inbound')->count());
        $this->assertSame(1, Activity::where('event', 'email.received')->where('subject_id', $lead->id)->count());
        Notification::assertSentToTimes($bd, InboundReplyNotification::class, 1);
    }

    public function test_automated_traffic_does_not_fan_out(): void
    {
        Notification::fake();
        [$bd, $lead, $outbound] = $this->scenario();

        app(InboundEmailService::class)->ingest($this->reply($lead, $outbound, [
            'fromEmail' => 'mailer-daemon@mail.gmail.com',
            'headers' => ['auto-submitted' => 'auto-replied', 'from' => 'mailer-daemon@mail.gmail.com'],
        ]));

        $lead->refresh();
        $this->assertSame('sent', $lead->email_outreach); // unchanged
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('activities', ['event' => 'planner-event.completed', 'subject_id' => $lead->id]);
    }

    public function test_unmatched_reply_persists_but_does_not_fan_out(): void
    {
        Notification::fake();
        [$bd, $lead, $outbound] = $this->scenario();

        // A sender that matches no lead and threads to no known outbound message.
        $orphan = Email::create([
            'lead_id' => null, 'user_id' => null, 'direction' => 'outbound',
            'to_email' => 'x@y.test', 'subject' => 's', 'body' => 'b', 'provider' => 'smtp',
            'message_id' => 'unknown-'.uniqid().'@crm.test', 'status' => 'sent', 'sent_at' => now(),
        ]);
        $msg = new InboundMessage(
            provider: 'gmail', mailbox: 'crm@company.test',
            fromEmail: 'stranger@nowhere.test', fromName: null, toEmail: 'crm@company.test',
            subject: 'Hello', bodyText: 'hi', messageId: 'in-'.uniqid().'@x',
            inReplyTo: null, references: [], headers: ['from' => 'stranger@nowhere.test'],
        );

        $email = app(InboundEmailService::class)->ingest($msg);

        $this->assertNotNull($email);
        $this->assertNull($email->lead_id);
        Notification::assertNothingSent();
    }
}
