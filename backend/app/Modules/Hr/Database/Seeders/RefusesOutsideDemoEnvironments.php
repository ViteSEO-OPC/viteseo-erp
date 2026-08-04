<?php

namespace App\Modules\Hr\Database\Seeders;

use Illuminate\Support\Facades\Log;

/**
 * Makes an HR demo seeder a no-op outside local/testing (FIN-021).
 *
 * WHAT WAS WRONG. `php artisan db:seed` — the documented reset path, and the default any hosting
 * platform's deploy hook reaches for — called every demo seeder in this directory. Those create
 * demo employees on real salaries, fabricated payroll runs, fabricated payslips and backfilled
 * audit entries. Nothing distinguishes a fabricated payroll run from a real one afterwards: it
 * counts in reports, in YTD, in the 13th-month basis and in the audit trail. One accidental seed
 * against a live database poisons every financial figure in the system, and there is no flag to
 * filter it back out.
 *
 * WHY A TRAIT, NOT A BASE CLASS. These seeders take injected dependencies with signatures that
 * differ per seeder (`run(PayrollService $service, PayScheduleService $schedule)`, and so on).
 * A base class with a templated `run()` would have to fix one signature and break the rest.
 *
 * WHY NOT IN DatabaseSeeder. That file is shared with the CRM and Projects modules and calls
 * their demo seeders too. Splitting it is the complete fix and belongs to the platform owner;
 * this closes the HR half without reaching into another pod's reset flow. CRM and Projects demo
 * data is still seeded by `db:seed` in any environment — that half of FIN-021 is still open.
 */
trait RefusesOutsideDemoEnvironments
{
    /**
     * True when this seeder must not run — call it as the first line of `run()`.
     *
     * Fails CLOSED: anything that is not explicitly local or testing is treated as an environment
     * where fabricated payroll must not appear, so a mis-set or empty APP_ENV is safe rather than
     * permissive.
     */
    protected function demoSeedingBlocked(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return false;
        }

        // Logged rather than written to the console on purpose. This fires during `db:seed`, which
        // in the case that matters is running inside a deploy hook where nobody is watching stdout
        // — a log line survives to be found afterwards. (It also keeps the trait usable under
        // `$this->seed()` in tests, where `$this->command`'s output is a strict mock.)
        Log::info('hr.demo_seeder.skipped', [
            'seeder' => class_basename(static::class),
            'environment' => app()->environment(),
        ]);

        return true;
    }
}
