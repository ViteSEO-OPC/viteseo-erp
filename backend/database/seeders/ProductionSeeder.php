<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The ONLY seeder that runs in production:
 *
 *   php artisan db:seed --class=ProductionSeeder --force
 *
 * It seeds roles/permissions (required for onboarding to assign acc_admin /
 * inv_admin / booking_admin) and nothing else. NEVER run bare `db:seed` on a
 * real environment — DatabaseSeeder chains ~28 demo seeders that create logins
 * with the password `password`.
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
