<?php

namespace App\Modules\Crm\Services\Integration;

use App\Modules\Crm\Jobs\DispatchN8nWebhookJob;
use Illuminate\Support\Str;

/**
 * RC3 Phase 5 — CRM → n8n webhook emitter (Direction B). Builds a signed, versioned, idempotent envelope
 * for a domain event and dispatches it asynchronously (queued job) to the configured n8n endpoint. A
 * no-op when n8n is disabled or unconfigured, so wiring it into events is always safe. n8n consumes these
 * events — it never drives CRM state (FROZEN: CRM is the source of truth).
 */
class N8nWebhookEmitter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function emit(string $topic, array $data): void
    {
        $url = config('n8n.webhook_url');

        if (! config('n8n.enabled') || ! is_string($url) || $url === '') {
            return;
        }

        DispatchN8nWebhookJob::dispatch([
            'event_id' => (string) Str::uuid(),   // stable id for at-least-once dedupe on the n8n side
            'topic' => $topic,
            'schema_version' => 1,
            'occurred_at' => now()->toIso8601String(),
            'data' => $data,
        ]);
    }
}
