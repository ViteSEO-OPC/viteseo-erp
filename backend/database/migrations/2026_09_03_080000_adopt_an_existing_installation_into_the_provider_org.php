<?php

declare(strict_types=1);

use App\Models\Org;
use App\Models\Subscription;
use App\Support\Entitlements;
use App\Support\SystemRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give an installation that predates tenancy an org to belong to.
 *
 * The migrations that follow stamp org_id across HR, CRM and Projects by
 * copying "the first org" onto every existing row. On a fresh install there is
 * nothing to copy and they correctly no-op. On a LIVE install there is
 * everything to copy and still no org, because the only two things that ever
 * create one are the seeder (fresh installs only) and client onboarding.
 *
 * Nothing would have crashed. The backfills are guarded: with no org they leave
 * org_id nullable and every row NULL, and the deploy reports success. Then
 * CurrentOrg::id() fails closed for a user with no membership, so the first
 * person to sign in after the deploy meets an audit-log insert with a null
 * org_id -- and behind that, had they got in, an empty system. A silent
 * blackout that looks like a good deploy.
 *
 * Membership alone is not enough either. `subscription-active` refuses an org
 * with NO subscription, not merely a lapsed one, and `feature:<module>` refuses
 * a module the org never availed. The provider needs both or its own staff meet
 * the gates written for its clients.
 *
 * Deliberately skipped: super_admin. CurrentOrg falls back to the first org for
 * that role precisely so the platform surfaces work across tenants, and a
 * membership would pin them to one -- the opposite of the job.
 *
 * Runs ONCE, and only where it is needed: an org already present means a seeded
 * or already-adopted install, and no users at all means a fresh one the seeder
 * is about to fill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orgs') || ! Schema::hasTable('users')) {
            return;
        }

        if (DB::table('orgs')->exists()) {
            return; // seeded, or adopted by an earlier run of this migration
        }

        $users = DB::table('users');
        if (Schema::hasColumn('users', 'deleted_at')) {
            $users->whereNull('deleted_at');
        }
        if (! $users->exists()) {
            return; // a fresh install: the seeder owns this
        }

        $provider = Org::firstOrCreate(
            ['name' => 'Vite SEO Digital Advertising OPC'],
            ['is_active' => true],
        );

        // The provider avails everything it sells, so no gate can shut its own
        // staff out of a module they built.
        //
        // Read off SystemRegistry rather than listed by hand. The list used to say
        // hr/crm/pms directly, one line under this same sentence, and the sentence
        // stopped being true the moment a fourth system was sold: Accounting came
        // back on 2026-09-05 and the provider org did not avail it, so ViteSEO's own
        // super admin met the locked tile written for clients who had not paid.
        $features = [];
        foreach (SystemRegistry::keys() as $system) {
            $features = [...$features, ...SystemRegistry::registry($system)::get('full')['features']];
        }
        Entitlements::sync($provider->id, array_values(array_unique($features)));

        Subscription::firstOrCreate(
            ['org_id' => $provider->id],
            [
                'plan' => 'full',
                'monthly_price' => 0,
                // NOT NULL, default 10; the provider's own staff exceed that.
                'seat_limit' => 500,
                'status' => Subscription::ACTIVE,
                'current_period_end' => now()->addYears(5),
            ],
        );

        $superAdminIds = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'super_admin')
            ->where('model_has_roles.model_type', \App\Models\User::class)
            ->pluck('model_has_roles.model_id');

        $memberIds = (clone $users)->whereNotIn('id', $superAdminIds)->pluck('id');

        foreach ($memberIds as $userId) {
            DB::table('org_user')->insert([
                'org_id' => $provider->id,
                'user_id' => $userId,
                'is_default' => true,
            ]);
        }
    }

    /**
     * Not reversed. Dropping the org would orphan every row the migrations after
     * this one stamped with it, and re-running up() rebuilds it from nothing.
     */
    public function down(): void {}
};
