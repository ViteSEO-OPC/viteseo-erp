<?php

namespace App\Modules\Hr\Console\Commands;

use App\Modules\Hr\Services\LeaveAccrualService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Accrues leave, carries last year's unused days forward, and expires what has lapsed (FIN-034).
 *
 * Runs on the 1st of each month from HrServiceProvider, and does all three steps in the order they
 * depend on each other: roll over first (so January's balance exists and knows its carried figure),
 * then accrue this month, then drop anything whose expiry has passed.
 *
 * SAFE TO RUN AT ANY TIME, ANY NUMBER OF TIMES. `LeaveAccrualService` recomputes totals from stored
 * components rather than incrementing them, so a double cron fire, a manual catch-up after a missed
 * month, or an HR officer running it to see what happens all converge on the same balances. That is
 * why there is no lock file and no "already ran this month" guard — there is nothing to guard.
 *
 *   php artisan hr:leave-accrual
 *   php artisan hr:leave-accrual --year=2026
 *   php artisan hr:leave-accrual --as-of=2026-06-30 --no-rollover
 */
class RunLeaveAccrual extends Command
{
    protected $signature = 'hr:leave-accrual
        {--year= : Calendar year to accrue (defaults to the current Manila year)}
        {--as-of= : Accrue as though this were today (YYYY-MM-DD) — months after it are not credited}
        {--no-rollover : Skip carrying last year\'s unused days forward}
        {--no-expiry : Skip expiring carried days that have lapsed}';

    protected $description = 'Accrue leave, carry over unused days and expire lapsed carry-over';

    public function handle(LeaveAccrualService $accrual): int
    {
        $tz = config('app.timezone_local');
        $asOf = $this->option('as-of')
            ? Carbon::parse($this->option('as-of'), $tz)
            : Carbon::now($tz);

        $year = (int) ($this->option('year') ?: $asOf->year);

        // Rollover FIRST: accrual recomputes `entitled_days` from accrued + carried, so carrying
        // afterwards would leave the total a month stale until the next run.
        if (! $this->option('no-rollover')) {
            $carried = $accrual->rolloverInto($year);
            $this->info("Carried unused leave into {$year}: {$carried} balance(s).");
        }

        $accrued = $accrual->accrue($year, $asOf->toDateString());
        $this->info("Accrued {$year} through {$asOf->format('F')}: {$accrued} balance(s) updated.");

        if (! $this->option('no-expiry')) {
            $expired = $accrual->expireCarriedDays($asOf->toDateString());
            $this->info("Expired lapsed carry-over: {$expired} balance(s).");
        }

        return self::SUCCESS;
    }
}
