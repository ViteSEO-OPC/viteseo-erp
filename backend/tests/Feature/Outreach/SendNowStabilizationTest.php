<?php

namespace Tests\Feature\Outreach;

use App\Models\User;
use App\Modules\Crm\Events\OutboundEmailSent;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailEvent;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Mail\Contracts\EmailProvider;
use App\Modules\Crm\Services\Mail\EmailMessage;
use App\Modules\Crm\Services\Mail\SentEmail;
use App\Modules\Crm\Services\Outreach\OutreachEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * EMAIL-001 — Immediate Send Stabilization. The synchronous Send-Now path (OutreachEmailService::sendToLead)
 * now persists BEFORE transport, so every outcome — success, transport failure, or a pre-flight recipient
 * error — is durably recorded (or correctly refused) instead of vanishing on a thrown exception. These tests
 * pin the three guarantees; the scheduled path (ScheduledEmailService) is untouched and out of scope here.
 */
class SendNowStabilizationTest extends TestCase
{
    use RefreshDatabase;

    /** Swap the transport for a stub that succeeds, returning a fixed provider result. */
    private function fakeSendingSucceeds(): void
    {
        $this->app->instance(EmailProvider::class, new class implements EmailProvider
        {
            public function send(EmailMessage $message): SentEmail
            {
                return new SentEmail(provider: 'smtp', messageId: 'test-message-id@crm.test');
            }
        });
    }

    /** Swap the transport for a stub that throws, simulating an SMTP transport failure. */
    private function fakeSendingFails(): void
    {
        $this->app->instance(EmailProvider::class, new class implements EmailProvider
        {
            public function send(EmailMessage $message): SentEmail
            {
                throw new \RuntimeException('SMTP connection refused');
            }
        });
    }

    private function service(): OutreachEmailService
    {
        return app(OutreachEmailService::class);
    }

    public function test_successful_send_now_persists_a_sent_email_with_full_audit_history(): void
    {
        Event::fake([OutboundEmailSent::class]);
        $this->fakeSendingSucceeds();

        $sender = User::factory()->create();
        $lead = Lead::factory()->create(['assigned_to' => $sender->id, 'email' => 'prospect@acme.test']);

        $email = $this->service()->sendToLead($lead, $sender, 'Hello', 'Body copy');

        // The row is persisted and terminal-sent.
        $this->assertSame('sent', $email->status);
        $this->assertNotNull($email->sent_at);
        $this->assertNull($email->queued_at, 'Synchronous Send-Now must never enter the queue.');
        $this->assertSame(1, Email::count());

        // Audit timeline: sending → sent.
        $this->assertTrue(EmailEvent::where('email_id', $email->id)->where('type', 'sending')->exists());
        $this->assertTrue(EmailEvent::where('email_id', $email->id)->where('type', 'sent')->exists());

        // Centralized Activity log entry (unchanged behaviour).
        $this->assertTrue($lead->activities()->where('event', 'email.sent')->exists());

        // Downstream domain event (n8n seam) fires exactly as before.
        Event::assertDispatched(OutboundEmailSent::class);
    }

    public function test_transport_failure_persists_a_failed_email_and_rethrows_the_original_exception(): void
    {
        Event::fake([OutboundEmailSent::class]);
        $this->fakeSendingFails();

        $sender = User::factory()->create();
        $lead = Lead::factory()->create(['assigned_to' => $sender->id, 'email' => 'prospect@acme.test']);

        $caught = null;
        try {
            $this->service()->sendToLead($lead, $sender, 'Hello', 'Body copy');
        } catch (\Throwable $e) {
            $caught = $e;
        }

        // The ORIGINAL exception propagates unchanged (preserves today's error UX).
        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertSame('SMTP connection refused', $caught->getMessage());

        // The email is still persisted — as terminal failed, with the reason retained.
        $this->assertSame(1, Email::count());
        $email = Email::first();
        $this->assertSame('failed', $email->status);
        $this->assertNotNull($email->failed_at);
        $this->assertStringContainsString('SMTP connection refused', (string) $email->error);
        $this->assertNull($email->sent_at);

        // Audit timeline: sending → failed (never sent).
        $this->assertTrue(EmailEvent::where('email_id', $email->id)->where('type', 'sending')->exists());
        $this->assertTrue(EmailEvent::where('email_id', $email->id)->where('type', 'failed')->exists());
        $this->assertFalse(EmailEvent::where('email_id', $email->id)->where('type', 'sent')->exists());

        // No success side effects.
        $this->assertFalse($lead->activities()->where('event', 'email.sent')->exists());
        Event::assertNotDispatched(OutboundEmailSent::class);
    }

    public function test_missing_recipient_is_refused_before_anything_is_persisted(): void
    {
        Event::fake([OutboundEmailSent::class]);
        $this->fakeSendingSucceeds(); // provider must never be reached

        $sender = User::factory()->create();
        $lead = Lead::factory()->create(['assigned_to' => $sender->id, 'email' => '']);

        $caught = null;
        try {
            $this->service()->sendToLead($lead, $sender, 'Hello', 'Body copy');
        } catch (ValidationException $e) {
            $caught = $e;
        }

        // The existing validation error is returned (the `to` field), pre-flight.
        $this->assertInstanceOf(ValidationException::class, $caught);
        $this->assertArrayHasKey('to', $caught->errors());

        // Nothing was persisted — no email row, no audit events.
        $this->assertSame(0, Email::count());
        $this->assertSame(0, EmailEvent::count());
        Event::assertNotDispatched(OutboundEmailSent::class);
    }
}
