<?php

namespace App\Modules\Hr\Console\Commands;

use App\Modules\Hr\Services\ManagementAttendanceService;
use App\Modules\Hr\Services\ViolationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Writes the attendance rows for management staff (see ManagementAttendanceService).
 *
 * Runs nightly from HrServiceProvider. The default window is the last 7 days, not
 * just today, so a missed or failed run self-heals on the next one instead of
 * leaving a permanent hole that the violation engine would read as an absence.
 *
 *   php artisan hr:generate-management-attendance
 *   php artisan hr:generate-management-attendance --days=30
 *   php artisan hr:generate-management-attendance --from=2026-07-03 --to=2026-07-24 --dry-run
 */
class GenerateManagementAttendance extends Command
{
    protected $signature = 'hr:generate-management-attendance
        {--days=7 : How many days back from today to cover (ignored when --from is given)}
        {--from= : First Manila date to cover (YYYY-MM-DD)}
        {--to= : Last Manila date to cover (YYYY-MM-DD, defaults to today)}
        {--dry-run : Report what would be written without touching the database}
        {--no-recompute : Skip the violation recompute afterwards}';

    protected $description = 'Generate attendance rows for attendance-exempt management staff';

    public function handle(ManagementAttendanceService $service, ViolationService $violations): int
    {
        $tz = config('app.timezone_local');
        $today = now($tz)->startOfDay();

        $to = $this->option('to')
            ? Carbon::parse($this->option('to'), $tz)->startOfDay()
            : $today->copy();

        $from = $this->option('from')
            ? Carbon::parse($this->option('from'), $tz)->startOfDay()
            : $to->copy()->subDays(max(1, (int) $this->option('days')) - 1);

        // Never invent presence for a day that has not happened yet.
        if ($to->gt($today)) {
            $to = $today->copy();
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '%sManagement attendance %s → %s',
            $dryRun ? '[dry run] ' : '',
            $from->toDateString(),
            $to->toDateString(),
        ));

        $stats = $service->generate($from->toDateString(), $to->toDateString(), $dryRun);

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

        $written = $stats['created'] + $stats['updated'];

        // New rows clear the bogus absences those days used to produce, but the stored
        // violations only change when they are recomputed.
        if (! $dryRun && $written > 0 && ! $this->option('no-recompute')) {
            for ($m = $from->copy()->startOfMonth(); $m->lte($to); $m->addMonth()) {
                $violations->recompute($m->format('Y-m'));
            }
            $this->line('  Violations recomputed.');
        }

        return self::SUCCESS;
    }
}
