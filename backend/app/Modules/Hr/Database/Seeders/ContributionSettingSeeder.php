<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Support\StatutoryContributions;
use Illuminate\Database\Seeder;

/**
 * Government contributions, as data (completed in B8).
 *
 * The definitions themselves moved to StatutoryContributions, because SSS / PhilHealth /
 * Pag-IBIG are system data rather than demo data: an installation that only ran `migrate` used
 * to end up with an empty "Government mandated" section it could not fill. They are now
 * installed by 2026_08_20_000002_install_statutory_contributions, so this seeder is a no-op on
 * any migrated database — kept because the payroll tests seed it explicitly, and so that
 * `db:seed` still repairs a database somebody has deleted rows out of.
 */
class ContributionSettingSeeder extends Seeder
{
    public function run(): void
    {
        StatutoryContributions::install();
    }
}
