<?php

namespace Tests\Feature\Outreach;

use App\Models\User;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Notifications\InboundReplyNotification;
use App\Modules\Crm\Services\Mail\Inbound\InboundMessage;
use App\Modules\Crm\Services\Outreach\InboundEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Item #3 (R2) — null Message-ID idempotency fallback. An inbound with no RFC Message-ID header must still
 * dedupe on Pub/Sub redelivery: InboundEmailService synthesizes a deterministic, provider-scoped surrogate
 * from the provider's own message id and stores it as message_id, so the existing (provider, message_id)
 * pre-check + unique index hold. Messages that DO carry a real Message-ID are unchanged.
 */
class InboundNullMessageIdDedupTest extends TestCase
{
    use RefreshDatabase;

    private function ingest(InboundMessage $m): ?Email
    {
        return app(InboundEmailService::class)->ingest($m);
    }

    /** @param array<string, mixed> $overrides */
    private function inbound(array $overrides = []): InboundMessage
    {
        return new InboundMessage(
            provider: 'gmail',
            mailbox: 'crm@company.test',
            fromEmail: $overrides['fromEmail'] ?? 'stranger@nowhere.test',
            fromName: null,
            toEmail: 'crm@company.test',
            subject: 'Hi',
            bodyText: 'body',
            messageId: $overrides['messageId'] ?? null,                     // default: NO RFC Message-ID
            inReplyTo: null,
            references: [],
            providerMessageId: $overrides['providerMessageId'] ?? 'gmail-abc123',
            providerThreadId: null,
            receivedAt: now()->toIso8601String(),
            headers: ['from' => $overrides['fromEmail'] ?? 'stranger@nowhere.test'],
        );
    }

    public function test_null_message_id_is_deduped_via_a_provider_scoped_surrogate(): void
    {
        Notification::fake();

        $this->ingest($this->inbound()); // first delivery
        $this->ingest($this->inbound()); // Pub/Sub redelivery of the SAME message

        // Exactly one row — the surrogate closed the null-Message-ID hole (pre-fix this would be two).
        $this->assertSame(1, Email::where('direction', 'inbound')->count());

        // Stored under the deterministic, provider-scoped surrogate (not null).
        $this->assertSame('gmail-nomid:gmail-abc123', Email::where('direction', 'inbound')->first()->message_id);
    }

    public function test_matched_human_reply_with_null_message_id_fans_out_once_on_redelivery(): void
    {
        Notification::fake();
        $bd = User::factory()->create();
        $lead = Lead::factory()->create([
            'assigned_to' => $bd->id, 'email' => 'contact@acme.test',
            'status' => 'awaiting-reply', 'email_outreach' => 'sent',
        ]);

        $message = $this->inbound(['fromEmail' => 'contact@acme.test', 'providerMessageId' => 'gmail-reply-1']);

        $this->ingest($message); // delivery
        $this->ingest($message); // redelivery

        // One inbound row, one received Activity, one notification — no double fan-out.
        $this->assertSame(1, Email::where('direction', 'inbound')->count());
        $this->assertSame(1, $lead->activities()->where('event', 'email.received')->count());
        Notification::assertSentToTimes($bd, InboundReplyNotification::class, 1);
        $this->assertSame('replied', $lead->refresh()->email_outreach);
    }

    public function test_real_message_id_path_is_unchanged(): void
    {
        Notification::fake();

        $withId = $this->inbound(['messageId' => 'real-abc@mail.gmail.com', 'providerMessageId' => 'gmail-xyz']);
        $this->ingest($withId);
        $this->ingest($withId); // redelivery dedupes on the real Message-ID

        $this->assertSame(1, Email::where('direction', 'inbound')->count());
        $this->assertSame('real-abc@mail.gmail.com', Email::where('direction', 'inbound')->first()->message_id);
    }

    public function test_both_ids_null_still_persists_without_crashing(): void
    {
        Notification::fake();

        // Neither a Message-ID nor a provider id — unreachable for a fetched Gmail message; handled defensively.
        $message = new InboundMessage(
            provider: 'gmail', mailbox: 'crm@company.test',
            fromEmail: 'stranger@nowhere.test', fromName: null, toEmail: 'crm@company.test',
            subject: 'Hi', bodyText: 'body',
            messageId: null, inReplyTo: null, references: [],
            providerMessageId: null, providerThreadId: null,
            receivedAt: now()->toIso8601String(), headers: ['from' => 'stranger@nowhere.test'],
        );

        $this->ingest($message);

        $this->assertSame(1, Email::where('direction', 'inbound')->count());
        $this->assertNull(Email::where('direction', 'inbound')->first()->message_id);
    }
}
