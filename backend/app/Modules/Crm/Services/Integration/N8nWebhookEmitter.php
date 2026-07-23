<?php

namespace App\Modules\Crm\Services\Integration;

use App\Modules\Crm\Jobs\DispatchN8nWebhookJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RC3 Phase 5 — CRM → n8n webhook emitter (Direction B). Builds a signed, versioned, idempotent envelope
 * for a domain event and dispatches it asynchronously (queued job) to the topic's n8n endpoint. A
 * no-op when n8n is disabled or unconfigured, so wiring it into events is always safe. n8n consumes these
 * events — it never drives CRM state (FROZEN: CRM is the source of truth).
 *
 * Delivery is routed per topic (config `n8n.routes`): the configured base URL + the consuming workflow's
 * webhook path. This is transport addressing only — the CRM still decides the event; n8n still only
 * orchestrates.
 */
class N8nWebhookEmitter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function emit(string $topic, array $data): void
    {
        if (! config('n8n.enabled')) {
            return;
        }

        $url = $this->urlFor($topic);

        if ($url === null) {
            return;
        }

        DispatchN8nWebhookJob::dispatch([
            'event_id' => (string) Str::uuid(),   // stable id for at-least-once dedupe on the n8n side
            'topic' => $topic,
            'schema_version' => 1,
            'occurred_at' => now()->toIso8601String(),
            'data' => $data,
        ], $url);
    }

    /**
     * Resolve the delivery URL for a topic: base + the consuming workflow's webhook path. Returns null
     * (a silent, logged no-op) when n8n has no base URL configured, or when no workflow consumes the
     * topic — an unmapped topic is a deliberate non-delivery, not an error.
     */
    private function urlFor(string $topic): ?string
    {
        $base = config('n8n.webhook_url');

        if (! is_string($base) || $base === '') {
            return null;
        }

        /** @var array<string, string> $routes */
        $routes = (array) config('n8n.routes', []);
        $path = $routes[$topic] ?? null;

        if (! is_string($path) || $path === '') {
            Log::debug('n8n.webhook.unrouted', ['topic' => $topic]);

            return null;
        }

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }
}
