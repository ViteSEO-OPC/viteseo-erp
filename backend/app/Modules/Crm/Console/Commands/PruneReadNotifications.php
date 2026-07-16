<?php

namespace App\Modules\Crm\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notification retention policy (approved 2026-07-16):
 *   - UNREAD notifications are NEVER deleted (they are still actionable).
 *   - READ notifications are pruned once they are older than the retention window (default 90 days).
 *
 * This is safe because the `notifications` table is a transient in-app delivery inbox, NOT the system
 * of record — the append-only Activity log remains the permanent audit trail. Scheduled daily in
 * routes/console.php; also runnable on demand.
 */
class PruneReadNotifications extends Command
{
    protected $signature = 'notifications:prune-read {--days=90 : Read-notification retention window in days}';

    protected $description = 'Delete READ notifications older than the retention window (never touches unread)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $deleted = DatabaseNotification::query()
            ->whereNotNull('read_at')       // unread (read_at IS NULL) is always preserved
            ->where('read_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} read notification(s) read before {$cutoff->toDateString()} (>{$days}d).");

        return self::SUCCESS;
    }
}
