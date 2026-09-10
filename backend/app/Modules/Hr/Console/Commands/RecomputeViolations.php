<?php

namespace App\Modules\Hr\Console\Commands;

use App\Modules\Hr\Services\ViolationService;
use App\Support\CurrentOrg;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Item 11 — the nightly half of "violations are written, not computed on read".
 *
 * Every other input to the violation scan has a write behind it that can announce the change:
 * a punch, an HR adjustment, a leave or half-day decision, a holiday, a suspension, a daily
 * override, a rules save. One input has no write at all — the day ending. A shift nobody
 * clocked into is not an absence while the day is still running, and becomes one the moment it
 * is over, with no row inserted and no button pressed to say so.
 *
 * This is the trigger for that: it runs just after the Manila midnight and rebuilds the day
 * that has just closed. It doubles as the safety net for anything a write path misses.
 */
class RecomputeViolations extends Command
{
    protected $signature = 'hr:recompute-violations
        {--month= : A single month to rebuild (YYYY-MM), instead of the default window}
        {--all : Rebuild every month since the org started tracking attendance}';

    protected $description = 'Rebuild attendance violation flags (nightly; also the manual catch-up)';

    /**
     * TENANT FENCE: this is the NIGHTLY rebuild and the console has no authenticated
     * user, so `CurrentOrg::id()` fell through to the FIRST org — every other tenant's
     * closed day was never rebuilt, and an absence nobody clocked into simply never
     * became an absence. One pinned pass per tenant.
     */
    public function handle(ViolationService $violations): int
    {
        $status = self::SUCCESS;

        CurrentOrg::eachOrg(function (int $orgId) use ($violations, &$status): void {
            $this->line("Org #{$orgId}:");
            $status = max($status, $this->recomputeCurrentOrg($violations));
        });

        return $status;
    }

    /** The rebuild for whichever tenant is pinned. */
    private function recomputeCurrentOrg(ViolationService $violations): int
    {
        if ($this->option('all')) {
            $violations->recomputeHistory();
            $this->info('Rebuilt violation flags for every tracked month.');

            return self::SUCCESS;
        }

        if ($month = $this->option('month')) {
            if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
                $this->error('--month must look like 2026-08.');

                return self::FAILURE;
            }

            $violations->recompute($month);
            $this->info("Rebuilt violation flags for {$month}.");

            return self::SUCCESS;
        }

        // The day that just closed, plus the current month. On the 1st those are two different
        // months, which is exactly the case a "current month only" sweep would drop: the last
        // day of a month closes after that month has stopped being the current one.
        $tz = config('app.timezone_local');
        $yesterday = Carbon::now($tz)->subDay();
        $months = array_unique([$yesterday->format('Y-m'), Carbon::now($tz)->format('Y-m')]);

        foreach ($months as $m) {
            $violations->recompute($m);
        }

        $this->info('Rebuilt violation flags for ' . implode(' and ', $months) . '.');

        return self::SUCCESS;
    }
}
