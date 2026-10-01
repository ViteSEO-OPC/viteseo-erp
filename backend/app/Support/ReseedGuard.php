<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THE GUARD AGAINST RESETTING A LIVE DATABASE'S ACCESS OR LOGINS BY ACCIDENT.
 *
 * Three things in this codebase overwrite access state wholesale rather than adding to it:
 *  - RolesAndPermissionsSeeder `syncPermissions` on every built-in role: any permission granted or
 *    revoked by hand on a live system is silently undone;
 *  - DatabaseSeeder additionally resets every `@viteseo.ph` login's password to `password` and
 *    creates a super_admin with that password;
 *  - `platform:assign-roles` `syncRoles` every listed person to a fixed matrix.
 *
 * None of them runs automatically (no composer hook, no migration, no deploy script calls them), so
 * the risk is one operator command during a release — `db:seed --force`, `migrate --seed --force`,
 * `db:seed --class=ProductionSeeder --force`. This makes that command refuse unless it is in a
 * development environment (`local`, `testing`) or the operator names the EXACT database being
 * written in an opt-in variable:
 *
 *   ALLOW_ROLE_RESEED=<database>  php artisan db:seed --class=RolesAndPermissionsSeeder --force
 *   ALLOW_DEMO_SEED=<database>    php artisan db:seed --force          (demo/scratch rebuilds only)
 *
 * Naming the database (not `true`) means a variable left in a shell or an .env cannot authorize a
 * write to a different database than the one it was set for. An allowlist rather than a check for
 * "production" means a box with an unusual APP_ENV (the demo runs as `production`; a staging box
 * might say `staging`) is protected too.
 *
 * The opt-in is read with `env()`: a deliberate per-command shell variable. Production never caches
 * config, so an `ALLOW_*` line left in .env WOULD authorize — the release runbook greps for it (expect 0).
 */
final class ReseedGuard
{
    public const DEVELOPMENT_ENVIRONMENTS = ['local', 'testing'];

    /**
     * Databases that hold real data on this host. They need the explicit opt-in in EVERY environment —
     * so a checkout whose .env says `local` but points at production (a scratch clone, a laptop
     * tunnelled to the server) is protected too. Extend with RESEED_PROTECTED_DATABASES=a,b.
     */
    public const PROTECTED_DATABASES = ['teamora', 'teamora_demo'];

    public static function assertAllowed(string $what, string $optInVariable): void
    {
        $database = (string) DB::connection()->getDatabaseName();
        $protected = in_array($database, self::protectedDatabases(), true);

        if (! $protected && app()->environment(self::DEVELOPMENT_ENVIRONMENTS)) {
            return;
        }

        // A REAL environment variable for this one command (`ALLOW_ROLE_RESEED=teamora php artisan …`).
        // Under a config cache a value written in .env is invisible here, so only a deliberate shell
        // variable can authorize; never leave an ALLOW_* line in .env (the release runbook checks).
        $optIn = env($optInVariable);

        if (is_string($optIn) && $optIn !== '' && $optIn === $database) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s refused in the "%s" environment: it overwrites access/logins on database "%s". '
            .'It is never part of a release. If you really mean it, set %s=%s for this one command.',
            $what,
            app()->environment(),
            $database,
            $optInVariable,
            $database,
        ));
    }

    /** @return list<string> */
    private static function protectedDatabases(): array
    {
        $extra = array_filter(array_map('trim', explode(',', (string) env('RESEED_PROTECTED_DATABASES', ''))));

        return array_values(array_unique([...self::PROTECTED_DATABASES, ...$extra]));
    }
}
