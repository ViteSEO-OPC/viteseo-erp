<?php

namespace Tests\Feature\Outreach;

use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Models\User;
use App\Modules\Crm\Services\Mail\Inbound\InboundMessage;
use App\Modules\Crm\Services\Outreach\InboundEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The 4 lead-matching strategies in InboundEmailService::match(), ordered by confidence:
 * header-thread → provider-thread → sender-match → unmatched. Unmatched inbound is persisted with a
 * null lead (never dropped) and never fans out.
 */
class InboundMatchingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Lead, 1: Email}
     */
    private function leadWithOutbound(string $email, ?string $messageId, ?string $threadId): array
    {
        $bd = User::factory()->create();
        $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'email' => $email]);
        $outbound = Email::create([
            'lead_id' => $lead->id, 'user_id' => $bd->id, 'direction' => 'outbound',
            'to_email' => $email, 'subject' => 'Proposal', 'body' => 'Hi', 'provider' => 'smtp',
            'message_id' => $messageId, 'thread_id' => $threadId, 'status' => 'sent', 'sent_at' => now(),
        ]);

        return [$lead, $outbound];
    }

    private function ingest(InboundMessage $m): ?Email
    {
        return app(InboundEmailService::class)->ingest($m);
    }

    public function test_strategy_1_header_threading_matches_via_in_reply_to(): void
    {
        Notification::fake();
        [$lead] = $this->leadWithOutbound('a@acme.test', 'out-a@crm.test', null);

        $email = $this->ingest(new InboundMessage(
            provider: 'gmail', mailbox: 'crm@company.test',
            fromEmail: 'someone-else@acme.test', fromName: null, toEmail: 'crm@company.test',
            subject: 'Re: Proposal', bodyText: 'ok', messageId: 'in-a@x',
            inReplyTo: 'out-a@crm.test', references: ['out-a@crm.test'],
            headers: ['from' => 'someone-else@acme.test'],
        ));

        // Threaded to the lead even though the sender address differs from leads.email.
        $this->assertSame($lead->id, $email->lead_id);
    }

    public function test_strategy_2_provider_thread_id_matches(): void
    {
        Notification::fake();
        [$lead] = $this->leadWithOutbound('b@acme.test', 'out-b@crm.test', 'thread-b');

        $email = $this->ingest(new InboundMessage(
            provider: 'gmail', mailbox: 'crm@company.test',
            fromEmail: 'unknown@elsewhere.test', fromName: null, toEmail: 'crm@company.test',
            subject: 'Re: Proposal', bodyText: 'ok', messageId: 'in-b@x',
            inReplyTo: null, references: [], providerThreadId: 'thread-b',
            headers: ['from' => 'unknown@elsewhere.test'],
        ));

        $this->assertSame($lead->id, $email->lead_id);
    }

    public function test_strategy_3_sender_address_matches_lead_email_case_insensitively(): void
    {
        Notification::fake();
        [$lead] = $this->leadWithOutbound('c@acme.test', 'out-c@crm.test', null);

        $email = $this->ingest(new InboundMessage(
            provider: 'gmail', mailbox: 'crm@company.test',
            fromEmail: 'C@ACME.TEST', fromName: null, toEmail: 'crm@company.test',
            subject: 'Hello', bodyText: 'ok', messageId: 'in-c@x',
            inReplyTo: null, references: [], headers: ['from' => 'C@ACME.TEST'],
        ));

        $this->assertSame($lead->id, $email->lead_id);
    }

    public function test_strategy_4_unmatched_persists_with_null_lead_and_does_not_fan_out(): void
    {
        Notification::fake();

        $email = $this->ingest(new InboundMessage(
            provider: 'gmail', mailbox: 'crm@company.test',
            fromEmail: 'stranger@nowhere.test', fromName: null, toEmail: 'crm@company.test',
            subject: 'Cold', bodyText: 'hi', messageId: 'in-d@x',
            inReplyTo: null, references: [], headers: ['from' => 'stranger@nowhere.test'],
        ));

        $this->assertNotNull($email);
        $this->assertNull($email->lead_id);
        $this->assertSame('inbound', $email->direction);
        Notification::assertNothingSent();
    }
}
