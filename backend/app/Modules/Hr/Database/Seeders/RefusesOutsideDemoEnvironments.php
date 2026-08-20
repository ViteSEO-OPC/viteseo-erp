<?php

namespace App\Modules\Hr\Database\Seeders;

use Illuminate\Support\Facades\Log;

/** Makes an HR demo seeder a no-op outside local/testing. */
trait RefusesOutsideDemoEnvironments
{
    /** True when this seeder must not run — call it as the first line of `run()`. */
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
