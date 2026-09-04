<?php

namespace App\Modules\Hr\Console\Commands;

use App\Modules\Hr\Services\LeaveAccrualService;
use App\Support\CurrentOrg;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Accrues leave, carries last year's unused days forward, and expires what has lapsed. */
class RunLeaveAccrual extends Command
{
    protected $signature = 'hr:leave-accrual
        {--year= : Calendar year to accrue (defaults to the current Manila year)}
        {--as-of= : Accrue as though this were today (YYYY-MM-DD) — months after it are not credited}
        {--no-rollover : Skip carrying last year\'s unused days forward}
        {--no-expiry : Skip expiring carried days that have lapsed}';

    protected $description = 'Accrue leave, carry over unused days and expire lapsed carry-over';

    /**
     * TENANT FENCE: the console has no authenticated user, so `CurrentOrg::id()` falls
     * through to the FIRST org. Unpinned, this command did one tenant's work and reported
     * success — every other tenant's leave accrual simply never happened, with nothing
     * in the output to say so. One pinned pass per tenant instead.
     */
    public function handle(LeaveAccrualService $accrual): int
    {
        $tz = config('app.timezone_local');
        $asOf = $this->option('as-of')
            ? Carbon::parse($this->option('as-of'), $tz)
            : Carbon::now($tz);

        $year = (int) ($this->option('year') ?: $asOf->year);

        CurrentOrg::eachOrg(function (int $orgId) use ($accrual, $year, $asOf): void {
            $this->line("Org #{$orgId}:");

            // Rollover FIRST: accrual recomputes `entitled_days` from accrued + carried, so carrying
            // afterwards would leave the total a month stale until the next run.
            if (! $this->option('no-rollover')) {
                $carried = $accrual->rolloverInto($year);
                $this->info("  Carried unused leave into {$year}: {$carried} balance(s).");
            }

            $accrued = $accrual->accrue($year, $asOf->toDateString());
            $this->info("  Accrued {$year} through {$asOf->format('F')}: {$accrued} balance(s) updated.");

            if (! $this->option('no-expiry')) {
                $expired = $accrual->expireCarriedDays($asOf->toDateString());
                $this->info("  Expired lapsed carry-over: {$expired} balance(s).");
            }
        });

        return self::SUCCESS;
    }
}
