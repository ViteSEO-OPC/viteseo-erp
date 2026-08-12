<?php

namespace App\Modules\Crm\Console\Commands;

use App\Modules\Crm\Services\Import\XlsImportJobStore;
use Illuminate\Console\Command;

/**
 * Remove converted/source files left behind by ABANDONED `.xls` imports.
 *
 * A queued `.xls` conversion parks two files under `storage/app/xls-imports` — the uploaded original
 * and the generated `.xlsx` — and keys them to a cache record. Commit and failure delete both
 * eagerly. The gap this closes is the third ending: a conversion that is previewed and then simply
 * never confirmed. Its record expires on its own, but a cache expiry cannot unlink a file, so
 * without this the `.xlsx` would sit on disk indefinitely.
 *
 * Safety is decided entirely by {@see XlsImportJobStore::pruneOrphans()}: a file is removed only
 * when its job record is GONE **and** the file is older than the grace period. A live import — a BD
 * still reviewing a preview — is never touched, whatever its age.
 *
 * Idempotent, so the schedule can run it as often as is convenient; a second run finds nothing.
 */
class PruneXlsImportsCommand extends Command
{
    protected $signature = 'crm:prune-xls-imports
        {--grace=900 : Minimum age in seconds before an unreferenced file may be removed}';

    protected $description = 'Delete temporary .xls import files whose job record has expired';

    public function handle(XlsImportJobStore $store): int
    {
        $deleted = $store->pruneOrphans((int) $this->option('grace'));

        // Silent when there is nothing to do — this runs hourly and should not fill the log with
        // "removed 0 files" on a healthy system.
        if ($deleted > 0) {
            $this->info("Removed {$deleted} abandoned .xls import file(s).");
        }

        return self::SUCCESS;
    }
}
