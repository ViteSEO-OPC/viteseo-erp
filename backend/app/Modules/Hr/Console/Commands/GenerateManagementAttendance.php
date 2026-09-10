<?php

namespace App\Modules\Hr\Console\Commands;

use App\Modules\Hr\Services\ManagementAttendanceService;
use App\Support\CurrentOrg;
use Illuminate\Console\Command;

/** Writes the attendance rows for management staff (see ManagementAttendanceService). */
class GenerateManagementAttendance extends Command
{
    protected $signature = 'hr:generate-management-attendance
        {--days=7 : How many days back from today to cover (ignored when --from is given)}
        {--from= : First Manila date to cover (YYYY-MM-DD)}
        {--to= : Last Manila date to cover (YYYY-MM-DD, defaults to today)}
        {--dry-run : Report what would be written without touching the database}
        {--no-recompute : Skip the violation recompute afterwards}';

    protected $description = 'Generate attendance rows for attendance-exempt management staff';

    /**
     * TENANT FENCE: the console has no authenticated user, so `CurrentOrg::id()` fell
     * through to the FIRST org and only that tenant's management staff ever got their
     * attendance rows. One pinned pass per tenant.
     */
    public function handle(ManagementAttendanceService $service): int
    {
        CurrentOrg::eachOrg(function (int $orgId) use ($service): void {
            $this->line("Org #{$orgId}:");
            $this->generateForCurrentOrg($service);
        });

        return self::SUCCESS;
    }

    /** The generation for whichever tenant is pinned. */
    private function generateForCurrentOrg(ManagementAttendanceService $service): void
    {
        $dryRun = (bool) $this->option('dry-run');

        // The range, the generation and the violation recompute all live in the service, so
        // HR's regenerate button and this command cannot drift apart.
        $result = $service->run(
            from: $this->option('from'),
            to: $this->option('to'),
            days: (int) $this->option('days'),
            dryRun: $dryRun,
            recompute: ! $this->option('no-recompute'),
        );

        $stats = $result['stats'];

        $this->info(sprintf(
            '%sManagement attendance %s → %s',
            $dryRun ? '[dry run] ' : '',
            $result['from'],
            $result['to'],
        ));

        foreach ($stats['notes'] as $note) {
            $this->warn("  {$note}");
        }

        $this->line(sprintf(
            '  %d management employee(s): %d created, %d updated, %d skipped.',
            $stats['employees'],
            $stats['created'],
            $stats['updated'],
            $stats['skipped'],
        ));

        if ($result['recomputed']) {
            $this->line('  Violations recomputed.');
        }
    }
}
