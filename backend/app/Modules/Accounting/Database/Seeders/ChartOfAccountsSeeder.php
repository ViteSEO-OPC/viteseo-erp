<?php

namespace App\Modules\Accounting\Database\Seeders;

use App\Models\Org;
use App\Support\Entitlements;
use App\Modules\Accounting\Services\ChartOfAccountsProvisioner;
use Illuminate\Database\Seeder;

/**
 * Back-fills the Philippine chart of accounts for orgs that have none.
 *
 * Every new tenant gets its COA from OrgOnboardingService, which calls the same
 * provisioner; this seeder exists for the orgs that predate that call. It is
 * idempotent -- the provisioner skips accounts already there -- so running it
 * twice costs nothing.
 *
 * It used to create an org of its own, named after the vendor whose build this
 * module came from (`env('KIPLO_ORG_NAME', 'Kiplo')`). Running it here would
 * have put a competitor's name in a Vite SEO client's org list. A seeder that
 * back-fills data has no business inventing a tenant to hold it.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $provisioner = app(ChartOfAccountsProvisioner::class);

        // Only orgs that availed Accounting. An HRIS-only client has no use for a
        // chart of accounts, and thirty accounts they never bought sitting in a
        // module they cannot open is not a harmless extra -- it is the kind of
        // thing that turns up in a support call as "what is all this?".
        Org::query()->each(static function (Org $org) use ($provisioner): void {
            if (Entitlements::has($org->id, 'accounting')) {
                $provisioner->seedFor($org);
            }
        });
    }
}
