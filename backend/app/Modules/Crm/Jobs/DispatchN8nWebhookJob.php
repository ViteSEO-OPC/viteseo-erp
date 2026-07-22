<?php

namespace App\Modules\Crm\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * RC3 Phase 5 — delivers a CRM → n8n webhook. Runs on the existing `database` queue so the request path
 * never blocks; retries with backoff (at-least-once — the envelope's `event_id` lets n8n dedupe). Signs
 * the exact JSON body with HMAC-SHA256 (header `X-CRM-Signature: sha256=<hex>`) so n8n can verify
 * authenticity — the inverse of how the CRM verifies Google's OIDC push token on the inbound webhook.
 */
class DispatchN8nWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 30;

    /**
     * @param  array<string, mixed>  $envelope
     * @param  string  $url  Fully-resolved delivery endpoint (base + the topic's workflow path),
     *                       resolved by N8nWebhookEmitter so routing is decided once, at emit time.
     */
    public function __construct(public readonly array $envelope, public readonly string $url) {}

    public function handle(): void
    {
        $url = $this->url;
        if ($url === '') {
            return;
        }

        $body = (string) json_encode($this->envelope);
        $signature = hash_hmac('sha256', $body, (string) config('n8n.secret'));

        $response = Http::withHeaders(['X-CRM-Signature' => 'sha256='.$signature])
            ->timeout((int) config('n8n.timeout', 10))
            ->withBody($body, 'application/json')
            ->post($url);

        if ($response->failed()) {
            Log::warning('n8n.webhook.failed', [
                'topic' => $this->envelope['topic'] ?? null,
                'status' => $response->status(),
            ]);

            throw new RuntimeException('n8n webhook delivery failed: HTTP '.$response->status());
        }

        Log::info('n8n.webhook.sent', [
            'topic' => $this->envelope['topic'] ?? null,
            'event_id' => $this->envelope['event_id'] ?? null,
        ]);
    }
}
