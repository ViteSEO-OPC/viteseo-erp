<?php

declare(strict_types=1);

namespace App\Modules\Hr\Console\Commands;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeeService;
use App\Support\CurrentOrg;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Close the accounts of people whose last working day has arrived.
 *
 * A resignation is filed in advance and worked out: the person keeps doing the job
 * for the notice period, so `EmployeeService::separate()` records it and leaves the
 * account open. This is what closes it, on the day, without anyone having to
 * remember. A termination does not wait for this — that one closes immediately, in
 * separate() itself.
 *
 * Idempotent, and safe to run more than once a day: it only looks at accounts that
 * are still active, so a second pass finds nothing.
 */
class ApplySeparations extends Command
{
    protected $signature = 'hr:apply-separations
        {--as-of= : Treat this date as today (YYYY-MM-DD)}
        {--dry-run : List who would be closed without closing them}';

    protected $description = 'Close the accounts of employees whose last working day has passed';

    /**
     * TENANT FENCE: the console has no authenticated user, so `CurrentOrg::id()`
     * resolves to null and every org-scoped query reads nothing at all. One pinned
     * pass per tenant instead — the same fence hr:leave-accrual carries, and for the
     * same reason: unpinned, this would report success having done no work.
     */
    public function handle(EmployeeService $employees): int
    {
        $tz = config('app.timezone_local');
        $asOf = $this->option('as-of')
            ? Carbon::parse((string) $this->option('as-of'), $tz)
            : Carbon::now($tz);

        $dryRun = (bool) $this->option('dry-run');
        $closed = 0;

        CurrentOrg::eachOrg(function (int $orgId) use ($asOf, $dryRun, $employees, &$closed): void {
            // withTrashed(): separation soft-deletes the employee row and stamps
            // deleted_at with the last working day, so every row this command is
            // looking for is already trashed by the time it looks.
            $due = Employee::withTrashed()
                ->whereNotNull('separation_date')
                ->whereDate('separation_date', '<=', $asOf->toDateString())
                ->whereHas('user', fn ($q) => $q->where('status', '!=', User::STATUS_INACTIVE))
                ->with('user')
                ->get();

            foreach ($due as $employee) {
                $this->line(sprintf(
                    '  Org #%d: %s — last day %s%s',
                    $orgId,
                    $employee->user?->name ?? "employee #{$employee->id}",
                    $employee->separation_date?->toDateString() ?? '?',
                    $dryRun ? ' (dry run)' : '',
                ));

                if (! $dryRun) {
                    // Through the service, so the closing is audited like a termination's is.
                    $employees->closeAccount(
                        $employee,
                        'last working day '.($employee->separation_date?->toDateString() ?? '?'),
                    );
                    $closed++;
                }
            }
        });

        $this->info($dryRun
            ? 'Dry run — nothing was closed.'
            : "Closed {$closed} account(s).");

        return self::SUCCESS;
    }
}
