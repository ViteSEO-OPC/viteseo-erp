<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Org;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The deploy step that had nobody to write it.
 *
 * Vite SEO Systems has been live since July with sixteen people on it and no
 * orgs table row, because the only two things that create one are the seeder
 * (fresh installs) and client onboarding. The tenancy backfills copy "the first
 * org" onto every existing row, and against that database there is no first org
 * to copy -- so they no-op, the deploy reports success, and every user is locked
 * out of a system that still holds all their data.
 *
 * This rehearses that exact shape: a populated install with no org, then the
 * migration, then the thing that actually matters -- can the people who were
 * already using it still get in.
 */
class TheLiveInstallationGetsAnOrgTest extends TestCase
{
    use RefreshDatabase;

    /** Put the database back to how a pre-tenancy install looks. */
    private function asAPreTenancyInstall(): void
    {
        DB::table('org_user')->delete();
        DB::table('org_features')->delete();
        DB::table('subscriptions')->delete();
        DB::table('orgs')->delete();
        CurrentOrg::clear();
    }

    private function runTheMigration(): void
    {
        $path = database_path('migrations/2026_09_03_080000_adopt_an_existing_installation_into_the_provider_org.php');
        (require $path)->up();
    }

    public function test_the_people_already_using_it_can_still_sign_in(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $staff = User::factory()->create(['email' => 'kenneth@viteseo.ph']);
        $staff->assignRole('hr_officer');
        $staff->forceFill(['password' => Hash::make('Kenneth2026!')])->save();

        $this->asAPreTenancyInstall();

        // The state the deploy would have left behind: data, people, no org.
        $this->assertSame(0, Org::query()->count());
        $this->assertSame(0, DB::table('org_user')->where('user_id', $staff->id)->count());

        $this->runTheMigration();
        CurrentOrg::clear();

        $org = Org::query()->firstOrFail();
        $this->assertSame('Vite SEO Digital Advertising OPC', $org->name);

        // All three of these are load-bearing: membership or the sign-in dies on a
        // null audit org_id, the subscription or `subscription-active` refuses an
        // org that has none, the entitlements or `feature:` refuses every module.
        $this->assertSame($org->id, DB::table('org_user')->where('user_id', $staff->id)->value('org_id'));
        $this->assertNotNull(Subscription::query()->where('org_id', $org->id)->first());
        $this->assertGreaterThan(0, DB::table('org_features')->where('org_id', $org->id)->count());

        $this->postJson('/api/v1/auth/login', [
            'email' => 'kenneth@viteseo.ph',
            'password' => 'Kenneth2026!',
        ])->assertOk();
    }

    public function test_the_platform_admin_is_left_unattached(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $staff = User::factory()->create(['email' => 'staff@viteseo.ph']);
        $staff->assignRole('hr_officer');

        $platform = User::factory()->create(['email' => 'platform@viteseo.ph']);
        $platform->assignRole('super_admin');

        $this->asAPreTenancyInstall();
        $this->runTheMigration();

        // A super admin pinned to one tenant is the opposite of the job: the
        // fallback to the first org is what makes the cross-tenant screens work.
        $this->assertSame(0, DB::table('org_user')->where('user_id', $platform->id)->count());
        $this->assertSame(1, DB::table('org_user')->where('user_id', $staff->id)->count());
    }

    public function test_it_does_nothing_to_an_install_that_already_has_an_org(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        // The test harness pins an org of its own, so clear the slate first and
        // put back exactly one -- otherwise this asserts against the harness.
        $this->asAPreTenancyInstall();
        $existing = Org::create(['name' => 'Somebody Else', 'is_active' => true]);

        $this->runTheMigration();

        $this->assertSame(1, Org::query()->count());
        $this->assertSame('Somebody Else', Org::query()->firstOrFail()->name);
        $this->assertSame($existing->id, Org::query()->firstOrFail()->id);
    }
}
