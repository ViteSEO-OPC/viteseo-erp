<?php

namespace App\Modules\Crm\Jobs;

use App\Modules\Crm\Models\MailboxSync;
use App\Modules\Crm\Services\Mail\Contracts\MailboxSyncProvider;
use App\Modules\Crm\Services\Outreach\InboundEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Handles one inbound push notification: decode → resolve the mailbox cursor → fetch new
 * messages via the provider → ingest each through InboundEmailService (idempotent). The webhook
 * only enqueues this; all real work is async so Pub/Sub's at-least-once retries stay safe.
 */
class ProcessInboundNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 30;

    /**
     * @param  array<string, mixed>  $payload  the raw Pub/Sub envelope
     */
    public function __construct(private readonly array $payload) {}

    public function handle(MailboxSyncProvider $provider, InboundEmailService $inbound): void
    {
        $notification = $provider->parseNotification($this->payload);

        if ($notification->mailbox === '') {
            Log::warning('outreach.inbound.notification_no_mailbox', ['payload' => $this->payload]);

            return;
        }

        $sync = MailboxSync::query()->firstOrCreate(
            ['email' => $notification->mailbox],
            ['provider' => (string) config('outreach.inbound_provider', 'gmail'), 'status' => 'active'],
        );

        $result = $provider->fetchSince($notification->mailbox, $sync->sync_cursor);

        foreach ($result->messages as $message) {
            $inbound->ingest($message);
        }

        if ($result->cursor !== null) {
            $sync->update(['sync_cursor' => $result->cursor, 'last_synced_at' => now()]);
        }

        Log::info('outreach.inbound.synced', [
            'mailbox' => $notification->mailbox,
            'ingested' => count($result->messages),
        ]);
    }
}
