<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Subscription;
use App\Services\OrgOnboardingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Availing a plan has to CHANGE something.
 *
 * Until 2026-09-03 the HR, CRM and Projects route groups carried none of the three
 * gates the platform already had registered and already used elsewhere. So a plan
 * was a price list and nothing else: an org on Basic could reach every route Full
 * sells, an org that never availed a module could call all of it, and an org whose
 * subscription had lapsed -- or that never had one -- kept working exactly as
 * before. The paywall existed and was not switched on.
 *
 *   set-current-org      pins the tenant before anything reads
 *   subscription-active  402 on a lapsed or absent subscription
 *   feature:<module>     refuses a module the org never bought
 */
class ThePlanIsAFenceNotAPriceListTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{\App\Models\Org, \App\Models\User} */
    private function orgOn(array $systems, string $email): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $r = app(OrgOnboardingService::class)->createOrg(
            'Org '.$email, 'Owner', $email, 'password-123', $systems,
        );

        return [$r['org'], $r['admin']];
    }

    public function test_a_module_the_org_never_availed_is_refused(): void
    {
        // CRM only. HR was never bought.
        [, $admin] = $this->orgOn(['crm' => 'basic'], 'crm-only@client.ph');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/hr/employees')->assertStatus(403);
    }

    public function test_the_module_the_org_did_avail_is_reachable(): void
    {
        [, $admin] = $this->orgOn(['crm' => 'basic'], 'reachable@client.ph');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/crm/leads')->assertOk();
    }

    public function test_a_lapsed_subscription_is_paywalled_not_merely_warned(): void
    {
        [$org, $admin] = $this->orgOn(['crm' => 'basic'], 'lapsed-gate@client.ph');
        Sanctum::actingAs($admin);

        // Working before.
        $this->getJson('/api/v1/crm/leads')->assertOk();

        Subscription::query()->where('org_id', $org->id)->update([
            'status' => Subscription::SUSPENDED,
            'current_period_end' => now()->subDay(),
        ]);

        // 402, not 200-with-a-banner: the gate is the API, not the UI.
        $this->getJson('/api/v1/crm/leads')->assertStatus(402);
    }

    public function test_an_org_with_no_subscription_at_all_is_refused(): void
    {
        [$org, $admin] = $this->orgOn(['crm' => 'basic'], 'nosub@client.ph');
        Subscription::query()->where('org_id', $org->id)->delete();

        Sanctum::actingAs($admin);

        // "No row" must fail the same way "lapsed" does. An absent subscription is
        // the state a half-finished onboarding leaves behind, and reading it as
        // "nothing to check, carry on" is how a tenant ends up using the product for
        // free without anyone noticing.
        $this->getJson('/api/v1/crm/leads')->assertStatus(402);
    }

    /**
     * The platform's own administrator is not locked out by the gate they own.
     *
     * platform@viteseo.ph deliberately has NO org membership -- a membership would
     * pin them to one tenant, and seeing across all of them is the job. CurrentOrg
     * has one exception for exactly that: a super_admin with no membership resolves
     * to the provider org.
     *
     * SetCurrentOrg defeated it. It called `set($user->currentOrgId())`, which for
     * this user is `set(null)` -- and null is an OVERRIDE, not "no opinion", so
     * `id()` returned it before ever reaching the exception. The admin was then told
     * "your organization hasn't availed this feature" by a gate whose own fallback
     * would have answered correctly. The middleware killed the case it exists for.
     */
    public function test_a_super_admin_without_a_membership_still_reaches_the_modules(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        // The provider org, entitled, exactly as DatabaseSeeder leaves it.
        $provider = $this->tenant();
        \App\Support\Entitlements::enable($provider->id, \App\Support\FeatureRegistry::keys());

        $admin = \App\Models\User::create([
            'name' => 'Platform Admin',
            'email' => 'platform-gate@viteseo.ph',
            'password' => 'password',
        ]);
        $admin->syncRoles(['super_admin']);
        $this->assertSame(0, $admin->orgs()->count(), 'The fixture gave them a membership; the bug needs none.');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/hr/team')->assertOk();
        $this->getJson('/api/v1/crm/team')->assertOk();
        $this->getJson('/api/v1/projects/team')->assertOk();
    }
}
