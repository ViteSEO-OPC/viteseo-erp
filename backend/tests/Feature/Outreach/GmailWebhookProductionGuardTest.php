<?php

namespace Tests\Feature\Outreach;

use App\Modules\Crm\Jobs\ProcessInboundNotificationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Item #4 (R4) — production webhook verification guard. In production, OIDC verification is mandatory and the
 * `outreach.webhook.verify` disable flag is ignored, so the public Pub/Sub endpoint can never accept unsigned
 * payloads there even if GMAIL_WEBHOOK_VERIFY is misconfigured to false. Outside production the flag still
 * works (local/testing convenience) — the existing GmailWebhookTest covers that path.
 */
class GmailWebhookProductionGuardTest extends TestCase
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

    public function test_production_forces_verification_even_when_the_flag_is_disabled(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['outreach.webhook.verify' => false]); // escape hatch — must be IGNORED in production
        Queue::fake();

        $this->postJson('/api/v1/crm/webhooks/gmail', $this->payload('m-prod-1'))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_production_with_verification_on_still_rejects_an_untokened_request(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['outreach.webhook.verify' => true]);
        Queue::fake();

        $this->postJson('/api/v1/crm/webhooks/gmail', $this->payload('m-prod-2'))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_non_production_still_honours_the_disable_flag(): void
    {
        // Default test environment is not production; the flag remains a valid local/testing convenience.
        $this->assertFalse($this->app->isProduction());
        config(['outreach.webhook.verify' => false]);
        Queue::fake();

        $this->postJson('/api/v1/crm/webhooks/gmail', $this->payload('m-local-1'))
            ->assertNoContent();

        Queue::assertPushed(ProcessInboundNotificationJob::class, 1);
    }
}
