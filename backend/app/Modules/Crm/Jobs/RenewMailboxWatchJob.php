<?php

namespace App\Modules\Crm\Jobs;

use App\Modules\Crm\Models\MailboxSync;
use App\Modules\Crm\Services\Mail\Contracts\MailboxSyncProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Re-arms the mailbox watch before it lapses (push subscriptions expire — Gmail ≤ 7 days).
 * Scheduled daily. If no mailbox has been registered yet, seeds one from config('outreach.mailbox').
 */
class RenewMailboxWatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(MailboxSyncProvider $provider): void
    {
        $mailboxes = MailboxSync::query()->where('status', 'active')->get();

        if ($mailboxes->isEmpty()) {
            $configured = config('outreach.mailbox');
            if (! is_string($configured) || $configured === '') {
                return;
            }
            $mailboxes = collect([MailboxSync::query()->firstOrCreate(
                ['email' => $configured],
                ['provider' => (string) config('outreach.inbound_provider', 'gmail'), 'status' => 'active'],
            )]);
        }

        foreach ($mailboxes as $sync) {
            try {
                $registration = $provider->watch($sync->email);
                $sync->update([
                    // Only advance the cursor if we don't already have a newer one.
                    'sync_cursor' => $sync->sync_cursor ?? $registration->cursor,
                    'watch_expires_at' => $registration->expiresAt,
                    'status' => 'active',
                ]);
            } catch (\Throwable $e) {
                $sync->update(['status' => 'error']);
                Log::error('outreach.inbound.watch_renew_failed', [
                    'mailbox' => $sync->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
