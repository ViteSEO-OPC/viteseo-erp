<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use App\Models\Org;
use App\Models\User;
use App\Modules\Kiosk\Models\KioskAccessGrant;
use App\Modules\Kiosk\Models\KioskSelection;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end HTTP tests for the Kiosk module: permission gating, selection save
 * + catalog validation, the 24h temp-client access lifecycle (grant + expiry
 * enforcement), and tenant isolation.
 */
class KioskTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** Create an org + user with $role, pin the org, and authenticate. */
    private function actAs(string $role, string $orgName = 'Kiosk Org'): Org
    {
        $org = Org::create(['name' => $orgName]);
        // orgless(): the factory now puts every user in the default tenant, and
        // org_user.user_id is uniquely indexed (one org per user), so attaching a
        // second org here would violate that index rather than move the user.
        $user = User::factory()->orgless()->create();
        $org->users()->attach($user->id, ['is_default' => true]);
        $user->assignRole($role);
        CurrentOrg::set($org->id);
        Sanctum::actingAs($user);
        $this->user = $user;

        return $org;
    }

    private function validSelection(array $overrides = []): array
    {
        return array_merge([
            'prospect_name' => 'Ana Bakery',
            'business_type' => 'restaurant',
            'website_type' => 'informational',
            'brand_kit' => 'fresh_mint',
            'sections' => [
                'header' => 'classic',
                'footer' => 'simple',
                'pages' => [
                    ['name' => 'Home', 'slug' => 'home', 'hero' => 'split', 'sections' => [
                        ['section' => 'about', 'variant' => 'centered'],
                    ]],
                    ['name' => 'Contact', 'slug' => 'contact', 'hero' => null, 'sections' => [
                        ['section' => 'contact', 'variant' => 'split'],
                    ]],
                ],
            ],
        ], $overrides);
    }

    public function test_catalog_requires_authentication(): void
    {
        $this->getJson('/api/v1/kiosk/catalog')->assertUnauthorized();
    }

    public function test_user_without_kiosk_permission_is_forbidden(): void
    {
        // Was 'inv_viewer'. Inventory left this repo on 2026-09-03 and took its roles
        // with it, so the role no longer exists and the seeder cannot assign it. Any
        // real role without a kiosk permission proves the same thing.
        $this->actAs('hr_officer');
        $this->getJson('/api/v1/kiosk/catalog')->assertForbidden();
    }

    public function test_bd_gets_catalog(): void
    {
        $this->actAs('kiosk_bd');
        $this->getJson('/api/v1/kiosk/catalog')
            ->assertOk()
            ->assertJsonPath('data.business_types.0.key', 'restaurant')
            ->assertJsonStructure(['data' => ['business_types', 'website_types', 'sections', 'brand_kits']]);
    }

    public function test_bd_can_save_and_list_a_selection(): void
    {
        $this->actAs('kiosk_bd');

        $this->postJson('/api/v1/kiosk/selections', $this->validSelection())
            ->assertCreated()
            ->assertJsonPath('data.prospect_name', 'Ana Bakery')
            ->assertJsonPath('data.business_type_label', 'Restaurant / Food');

        $this->getJson('/api/v1/kiosk/selections')
            ->assertOk()
            ->assertJsonPath('data.0.prospect_name', 'Ana Bakery');
    }

    public function test_selection_rejects_unknown_business_type(): void
    {
        $this->actAs('kiosk_bd');
        $this->postJson('/api/v1/kiosk/selections', $this->validSelection(['business_type' => 'spaceship']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('business_type');
    }

    public function test_selection_saves_independent_theme_picks(): void
    {
        $this->actAs('kiosk_bd');

        $this->postJson('/api/v1/kiosk/selections', $this->validSelection([
            'typography' => 'space_inter',
            'type_scale' => 'spacious',
            'design_style' => 'playful',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.typography', 'space_inter')
            ->assertJsonPath('data.type_scale', 'spacious')
            ->assertJsonPath('data.design_style', 'playful');
    }

    public function test_selection_rejects_unknown_type_scale(): void
    {
        $this->actAs('kiosk_bd');
        $this->postJson('/api/v1/kiosk/selections', $this->validSelection(['type_scale' => 'gigantic']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type_scale');
    }

    public function test_selection_stores_a_logo_data_uri(): void
    {
        $this->actAs('kiosk_bd');

        $logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $this->postJson('/api/v1/kiosk/selections', $this->validSelection(['logo' => $logo]))
            ->assertCreated()
            ->assertJsonPath('data.logo', $logo);
    }

    public function test_selection_rejects_a_non_image_logo(): void
    {
        $this->actAs('kiosk_bd');
        $this->postJson('/api/v1/kiosk/selections', $this->validSelection(['logo' => 'https://evil.example/x.png']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('logo');
    }

    public function test_temp_client_can_configure_but_not_view_leads(): void
    {
        $org = $this->actAs('kiosk_client');
        KioskAccessGrant::create([
            'org_id' => $org->id, 'user_id' => $this->user->id, 'label' => 'Demo', 'expires_at' => now()->addDay(),
        ]);

        // Can run the configurator (catalog + save)…
        $this->getJson('/api/v1/kiosk/catalog')->assertOk();
        $this->postJson('/api/v1/kiosk/selections', $this->validSelection())->assertCreated();

        // …but cannot see the BD leads list.
        $this->getJson('/api/v1/kiosk/selections')->assertForbidden();
    }

    public function test_expired_temp_client_is_blocked(): void
    {
        $org = $this->actAs('kiosk_client');
        KioskAccessGrant::create([
            'org_id' => $org->id, 'user_id' => $this->user->id, 'label' => 'Demo', 'expires_at' => now()->subHour(),
        ]);

        $this->getJson('/api/v1/kiosk/catalog')->assertForbidden();
    }

    public function test_bd_generates_temp_client_access(): void
    {
        $this->actAs('kiosk_bd');

        $res = $this->postJson('/api/v1/kiosk/access', ['label' => "Ana's Bakery"])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'label', 'email', 'password', 'expires_at']]);

        // A kiosk_client user + an active grant were created.
        $email = $res->json('data.email');
        $client = User::where('email', $email)->first();
        $this->assertNotNull($client);
        $this->assertTrue($client->hasRole('kiosk_client'));

        $this->getJson('/api/v1/kiosk/access')
            ->assertOk()
            ->assertJsonPath('data.0.label', "Ana's Bakery")
            ->assertJsonPath('data.0.status', 'active');
    }

    public function test_selections_are_tenant_isolated(): void
    {
        // Org A saves a selection.
        $orgA = Org::create(['name' => 'Org A']);
        CurrentOrg::set($orgA->id);
        KioskSelection::create($this->validSelection(['prospect_name' => 'Secret A Corp']));

        // Org B's BD must not see Org A's selection.
        $this->actAs('kiosk_bd', 'Org B');
        $this->getJson('/api/v1/kiosk/selections')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
