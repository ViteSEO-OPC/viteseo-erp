<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Services\OrgOnboardingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Twenty of the twenty-one crm_* tables carry org_id. One does not.
 *
 * `crm_nerby_provider_settings` holds the discovery providers' API credentials,
 * and those are Vite SEO's: bought by us, billed to us, shared by every tenant.
 * Giving each org its own copy would invite a client to edit the platform's own
 * keys, so the table was deliberately left global and its migration promised the
 * matching safeguard -- writable by the platform super admin ALONE.
 *
 * The route carried `role:super_admin|crm_admin`, the same gate as the Gmail
 * settings sitting directly above it. Gmail is per-org and belongs there. This
 * is not: under that gate a client's own admin could swap or delete the
 * credential every other client's Nearby Clients depends on, and spend our money
 * testing it. A table with no tenant column is only as safe as the narrowest
 * gate in front of it.
 */
class ThePlatformsOwnKeysAreNotTheTenantsTest extends TestCase
{
    use RefreshDatabase;

    /** Every route that reads or writes the shared credential. */
    private const ROUTES = [
        ['getJson', '/api/v1/crm/settings/integrations/nerby', []],
        ['putJson', '/api/v1/crm/settings/integrations/nerby/provider', ['provider' => 'google']],
        ['putJson', '/api/v1/crm/settings/integrations/nerby/providers/google', ['enabled' => true]],
        ['deleteJson', '/api/v1/crm/settings/integrations/nerby/providers/google/credential', []],
        ['postJson', '/api/v1/crm/settings/integrations/nerby/providers/google/test', []],
    ];

    public function test_a_clients_admin_cannot_touch_the_platforms_credentials(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', 'maria@bituin.ph', 'password-123', ['crm' => 'full'],
        );

        Sanctum::actingAs($result['admin']);
        $this->assertTrue($result['admin']->hasRole('crm_admin'), 'guard: the client admin really holds crm_admin');

        foreach (self::ROUTES as [$verb, $url, $payload]) {
            $this->{$verb}($url, $payload)->assertForbidden("{$verb} {$url}");
        }
    }

    public function test_the_platform_super_admin_still_can(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        // An org must exist: a super admin with no membership resolves to the
        // provider org, and with none there is nothing to resolve to.
        app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', 'maria@bituin.ph', 'password-123', ['crm' => 'full'],
        );

        $platform = User::factory()->create(['email' => 'platform@viteseo.test']);
        $platform->assignRole('super_admin');
        Sanctum::actingAs($platform);

        // Only that the gate opens -- what the endpoint then does with a payload is
        // its own concern, and a validation error still proves the role passed.
        $this->getJson('/api/v1/crm/settings/integrations/nerby')->assertOk();
    }
}
