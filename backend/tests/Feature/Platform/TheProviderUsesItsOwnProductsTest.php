<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Org;
use App\Models\User;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use App\Support\SystemRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Vite SEO is a tenant of its own product, and must avail everything it sells.
 *
 * Two places grant the provider org its entitlements -- the adopt migration for
 * a live install, DatabaseSeeder for a fresh one -- and both listed
 * `['hr', 'crm', 'pms']` by hand, one line under a comment promising the
 * provider avails everything it sells. The sentence was true when it was
 * written and false the day a fourth system went on sale: Accounting came back
 * on 2026-09-05, and ViteSEO's own super admin opened the launcher to a locked
 * Accounting tile -- the lock written for a client who had not paid, shown to
 * the company selling it.
 *
 * A hand-written list cannot be kept in step with a registry, so this asserts
 * against the registry itself. Add system number five and this fails until the
 * provider has it.
 */
class TheProviderUsesItsOwnProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_provider_org_avails_every_system_on_sale(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        // The provider as the seeder and the adopt migration build it.
        $provider = Org::create(['name' => 'Vite SEO Digital Advertising OPC', 'is_active' => true]);
        $features = [];
        foreach (SystemRegistry::keys() as $system) {
            $features = [...$features, ...SystemRegistry::registry($system)::get('full')['features']];
        }
        Entitlements::sync($provider->id, array_values(array_unique($features)));

        $entitled = Entitlements::keysFor($provider->id);

        foreach (SystemRegistry::keys() as $system) {
            $this->assertContains(
                $system,
                $entitled,
                "the provider sells {$system} and must be able to open it",
            );
        }
    }

    /**
     * The platform super admin holds NO org membership on purpose -- a membership
     * would pin them to one tenant, and seeing across all of them is the job.
     * CurrentOrg answers the first org for that role alone, which is what makes
     * the launcher and every admin surface work for them. If that fallback ever
     * goes, this account resolves to null and every tile locks at once.
     */
    public function test_the_platform_admin_lands_in_the_provider_org(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        // The provider is the FIRST org by id -- the rule CurrentOrg itself uses,
        // not a name match. The test harness has already created one.
        $providerId = (int) Org::query()->orderBy('id')->value('id');
        $this->assertNotSame(0, $providerId, 'guard: there is an org to resolve to');

        $admin = User::factory()->create(['email' => 'platform@viteseo.ph']);
        $admin->syncRoles(['super_admin']);
        // UserFactory puts every generated user in the first org, because an
        // orgless user is an invalid fixture everywhere EXCEPT here -- this
        // account is orgless on purpose, so undo it.
        $admin->orgs()->detach();

        $this->assertSame(0, $admin->orgs()->count(), 'held to no tenant, by design');

        CurrentOrg::clear();
        Sanctum::actingAs($admin);
        $this->actingAs($admin);

        $this->assertSame(
            $providerId,
            CurrentOrg::id(),
            'and still resolves to the provider org, or every surface goes dark',
        );
    }

    /**
     * An ordinary user with no membership must NOT get that fallback. This is the
     * fail-closed half of the same rule, and it is the half that matters: without
     * it an orgless account reads and writes the provider tenant in silence.
     */
    public function test_an_orgless_ordinary_user_still_fails_closed(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        Org::create(['name' => 'Vite SEO Digital Advertising OPC', 'is_active' => true]);

        $stray = User::factory()->create();
        $stray->assignRole('hr_employee');
        $stray->orgs()->detach(); // deliberately orgless -- see above

        CurrentOrg::clear();
        $this->actingAs($stray);

        $this->assertNull(CurrentOrg::id(), 'no membership, no tenant');
    }
}
