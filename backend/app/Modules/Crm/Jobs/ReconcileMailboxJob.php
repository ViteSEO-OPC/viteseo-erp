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
 * Safety-net catch-up (scheduled hourly): fetches from each mailbox's stored cursor in case a
 * push notification was missed. Idempotent ingest means this can never double-persist. Also the
 * recovery path if a cursor goes stale.
 */
class ReconcileMailboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(MailboxSyncProvider $provider, InboundEmailService $inbound): void
    {
        foreach (MailboxSync::query()->where('status', 'active')->get() as $sync) {
            if ($sync->sync_cursor === null) {
                continue; // nothing to reconcile until a watch establishes a cursor
            }

            try {
                $result = $provider->fetchSince($sync->email, $sync->sync_cursor);

                foreach ($result->messages as $message) {
                    // Per-message isolation: one bad message must not abort this mailbox's loop or skip the
                    // cursor update below (which would re-fetch the poison every hour). Skip + log; continue.
                    try {
                        $inbound->ingest($message);
                    } catch (\Throwable $e) {
                        Log::error('outreach.inbound.ingest_failed', [
                            'mailbox' => $sync->email,
                            'message_id' => $message->messageId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                if ($result->cursor !== null) {
                    $sync->update(['sync_cursor' => $result->cursor, 'last_synced_at' => now()]);
                }
            } catch (\Throwable $e) {
                Log::error('outreach.inbound.reconcile_failed', [
                    'mailbox' => $sync->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
