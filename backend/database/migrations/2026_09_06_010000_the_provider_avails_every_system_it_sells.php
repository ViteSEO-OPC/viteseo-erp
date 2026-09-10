<?php

declare(strict_types=1);

use App\Models\Org;
use App\Modules\Accounting\Services\ChartOfAccountsProvisioner;
use App\Support\Entitlements;
use App\Support\SystemRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give the provider org every system the platform sells.
 *
 * Two places grant the provider its entitlements -- the adopt migration, for a
 * live install that predates tenancy, and DatabaseSeeder, for a fresh one. Both
 * said `['hr', 'crm', 'pms']` in a literal list, directly under a comment
 * promising the provider avails everything it sells. Both are now derived from
 * SystemRegistry, but neither will run again here: the migration returns early
 * once an org exists, and the seeder is not something you re-run on a box with
 * real data on it.
 *
 * So the installs that already exist need this one pass. Without it, ViteSEO's
 * own super admin opens the launcher and meets a locked Accounting tile -- the
 * lock written for a client who has not paid for it, shown to the company that
 * sells it.
 *
 * The provider is the FIRST org by id, which is not a guess: it is the same rule
 * CurrentOrg::id() uses to resolve a super_admin who holds no membership, so it
 * is by definition the org the platform admin is standing in.
 *
 * `enable`, never `sync`: this adds what is missing and removes nothing. An org
 * that deliberately turned a function off keeps it off, and re-running costs
 * nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orgs') || ! Schema::hasTable('org_features')) {
            return;
        }

        $providerId = DB::table('orgs')->orderBy('id')->value('id');
        if ($providerId === null) {
            return; // a fresh install: the seeder owns this
        }

        $features = [];
        foreach (SystemRegistry::keys() as $system) {
            $features = [...$features, ...SystemRegistry::registry($system)::get('full')['features']];
        }

        Entitlements::enable((int) $providerId, array_values(array_unique($features)));

        /*
         * An entitlement is permission to open a module, not a module ready to use.
         *
         * Accounting is the one that shows the difference. Granting it above unlocks
         * the tile; the books behind it are still empty, because a chart of accounts
         * is provisioned by OrgOnboardingService and the provider org was never
         * onboarded through it -- it predates the service. The result is a tile that
         * opens onto a New Entry form whose account picker has nothing in it, which
         * reads as a broken product rather than an unconfigured one.
         *
         * The provisioner is idempotent (updateOrCreate on org_id + code), so this
         * costs nothing on a box that already has them.
         */
        if (Schema::hasTable('acc_accounts')) {
            app(ChartOfAccountsProvisioner::class)->seedFor(Org::findOrFail($providerId));
        }
    }

    /**
     * Deliberately irreversible. Down() would have to decide WHICH features the
     * provider had before, and it cannot know: this migration adds only what was
     * missing, and stripping the lot would lock ViteSEO's own staff out of the
     * systems they were already using before it ran.
     */
    public function down(): void
    {
        //
    }
};
