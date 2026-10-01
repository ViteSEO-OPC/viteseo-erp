<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Roles/permissions only, for a FRESH database (first install of a new environment):
 *
 *   ALLOW_ROLE_RESEED=<database> php artisan db:seed --class=ProductionSeeder --force
 *
 * NOT for an existing live database and NEVER part of a release: RolesAndPermissionsSeeder
 * `syncPermissions` every built-in role, undoing grants made by hand. On a live system add the
 * specific permission instead (Permission::findOrCreate + givePermissionTo). Outside local/testing
 * it is refused unless ALLOW_ROLE_RESEED names the target database (see App\Support\ReseedGuard).
 * NEVER run bare `db:seed` on a real environment — DatabaseSeeder chains ~28 demo seeders that
 * create logins with the password `password` (it is refused there too).
 *
 * Idempotent config seeders (ChartOfAccountsSeeder, LeaveTypesSeeder, …) are
 * deliberately NOT chained here: chart of accounts is provisioned per-org by
 * OrgOnboardingService, and the HR config seeders belong to the HR rollout.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
