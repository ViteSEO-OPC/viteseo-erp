<?php

namespace App\Modules\Crm\Jobs;

use App\Modules\Crm\Models\MailboxSync;
use App\Modules\Crm\Services\Mail\Contracts\MailboxSyncProvider;
use App\Modules\Crm\Services\Outreach\InboundEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Synchronize ONE mailbox from its stored cursor. The unit of inbound work.
 *
 * This is the whole trigger mechanism: `outreach:sync` (scheduled every five minutes) dispatches
 * one of these per active mailbox. Nothing below the trigger changed when the integration moved off
 * Gmail push — the provider call, the ingest, the threading/dedup/attachment handling and the CRM
 * fan-out are the same code the Pub/Sub path used. A poll and a push differ only in what decides
 * that now is a good time to call `fetchSince()`.
 *
 * ── One job per mailbox, not one job for all of them ──────────────────────────────────────────
 *
 * A single job looping every mailbox makes one slow or failing mailbox everyone else's problem:
 * its Gmail latency delays the others, and a retry replays the whole set. Per-mailbox jobs isolate
 * failure, retry only the mailbox that failed, and let the queue run mailboxes concurrently.
 *
 * ── Why duplicates are impossible ─────────────────────────────────────────────────────────────
 *
 * Three independent layers, only the third of which is new:
 *
 *  1. ShouldBeUnique keyed on the mailbox — a five-minute cadence over a sync that occasionally
 *     takes longer than five minutes would otherwise stack overlapping runs against the same
 *     cursor. Laravel drops the duplicate dispatch while one is queued or running.
 *  2. The cursor itself — each run asks Gmail only for history after the last stored historyId, so
 *     a message is offered to ingest once in the normal case.
 *  3. InboundEmailService::ingest() — idempotent on (provider, message_id), enforced by a unique
 *     index as well as a pre-check. This is what makes overlap SAFE rather than merely unlikely,
 *     and it is why the bounded re-sync in the provider (bootstrap / expired-cursor recovery) can
 *     re-offer messages without creating a second Email row.
 *
 * Layer 1 is an optimisation; layer 3 is the guarantee. Do not remove layer 3 on the grounds that
 * layer 1 exists.
 */
class SyncMailboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retries are for TRANSPORT failures (Gmail 429/5xx, a dropped connection). Ingest failures are
     * already isolated per message below and never reach this.
     */
    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Bounds the uniqueness lock so a job killed mid-run (worker OOM, deploy restart) cannot wedge
     * this mailbox out of syncing forever. Comfortably longer than a normal sync, comfortably
     * shorter than a shift.
     */
    public int $uniqueFor = 900;

    public function __construct(public readonly string $mailbox) {}

    /** One in-flight sync per mailbox; a second dispatch for the same mailbox is dropped. */
    public function uniqueId(): string
    {
        return $this->mailbox;
    }

    public function handle(MailboxSyncProvider $provider, InboundEmailService $inbound): void
    {
        // firstOrCreate, not findOrFail: `outreach:sync --mailbox=` may name a mailbox that has no
        // row yet, and a null cursor is a valid starting state (the provider bootstraps from it).
        $sync = MailboxSync::query()->firstOrCreate(
            ['email' => $this->mailbox],
            ['provider' => (string) config('outreach.inbound_provider', 'gmail'), 'status' => 'active'],
        );

        try {
            $result = $provider->fetchSince($sync->email, $sync->sync_cursor);
        } catch (\Throwable $e) {
            // Mark the mailbox degraded and rethrow: the queue's retry/backoff is the right place to
            // handle a transient Gmail failure, and swallowing it here would report success.
            $sync->update(['status' => 'error']);

            Log::error('outreach.inbound.sync_failed', [
                'mailbox' => $sync->email,
                'cursor' => $sync->sync_cursor,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        foreach ($result->messages as $message) {
            // Per-message isolation: one malformed message must not abort the batch or block the
            // cursor advance below. An unmoved cursor re-fetches the same poison on every run, which
            // stalls the mailbox permanently — the failure mode this guard exists to prevent.
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

        // Advance even when every message failed to ingest, for the reason above. A null cursor means
        // the provider had nothing to advance to, so the stored one stays valid.
        $sync->update([
            'sync_cursor' => $result->cursor ?? $sync->sync_cursor,
            'last_synced_at' => now(),
            // Clears a previous failure once a run succeeds, so `status` reflects the CURRENT state
            // rather than the worst state ever reached.
            'status' => 'active',
        ]);

        Log::info('outreach.inbound.synced', [
            'mailbox' => $sync->email,
            'ingested' => count($result->messages),
            'cursor' => $sync->sync_cursor,
        ]);
    }
}
