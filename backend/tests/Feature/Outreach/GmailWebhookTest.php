<?php

namespace Tests\Feature\Outreach;

use App\Modules\Crm\Jobs\ProcessInboundNotificationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The public Gmail Pub/Sub push endpoint (`POST /api/webhooks/gmail`). It must: authenticate the
 * Google-signed OIDC token when verification is on, dedupe redundant Pub/Sub deliveries, enqueue the
 * async job, and never do business work inline. All work is deferred so at-least-once delivery is safe.
 */
class GmailWebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(string $messageId, array $data = ['emailAddress' => 'crm@company.test', 'historyId' => '42']): array
    {
        return [
            'message' => [
                'messageId' => $messageId,
                'data' => base64_encode((string) json_encode($data)),
            ],
            'subscription' => 'projects/p/subscriptions/s',
        ];
    }

    public function test_verification_off_enqueues_the_job_and_returns_204(): void
    {
        config(['outreach.webhook.verify' => false]);
        Queue::fake();

        $this->postJson('/api/v1/crm/webhooks/gmail', $this->payload('m-1'))
            ->assertNoContent();

        Queue::assertPushed(ProcessInboundNotificationJob::class, 1);
    }

    public function test_duplicate_pubsub_delivery_is_deduped_before_enqueue(): void
    {
        config(['outreach.webhook.verify' => false]);
        Queue::fake();

        $payload = $this->payload('m-dup');
        $this->postJson('/api/v1/crm/webhooks/gmail', $payload)->assertNoContent();
        $this->postJson('/api/v1/crm/webhooks/gmail', $payload)->assertNoContent(); // same messageId

        // Enqueued exactly once despite two deliveries.
        Queue::assertPushed(ProcessInboundNotificationJob::class, 1);
    }

    public function test_verification_on_rejects_a_request_with_no_oidc_token(): void
    {
        config(['outreach.webhook.verify' => true]);
        Queue::fake();

        $this->postJson('/api/v1/crm/webhooks/gmail', $this->payload('m-2'))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_verification_on_rejects_an_invalid_bearer_token(): void
    {
        config(['outreach.webhook.verify' => true]);
        Queue::fake();

        $this->postJson('/api/v1/crm/webhooks/gmail', $this->payload('m-3'), ['Authorization' => 'Bearer not-a-real-jwt'])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }
}
