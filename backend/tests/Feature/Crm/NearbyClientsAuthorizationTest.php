<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 🔒 RBAC Hardening R-2 — authorization for the Nearby Clients prospect pool (PO decision 2026-07-24).
 *
 * Nearby Clients is a Business Development workspace: BD + Super Admin only. Admin (CRM Manager) has NO
 * access, per the approved Admin module list (Dashboard · Leads · Reports & Performance · Activity Logs
 * · Settings). The route previously carried no authorization at all — RBAC audit finding 3, where a BD
 * token returned 200 against an endpoint nobody had gated.
 *
 * The Target Service Area registry is a SEPARATE surface and is deliberately NOT changed by this
 * milestone: its API stays Admin/Super-Admin-only, asserted below so this milestone cannot silently
 * widen or narrow it. Its only UI entry point remains the Nearby Clients header action, so in practice
 * only Super Admin reaches it once Admin loses that page — a deliberate, deferred consequence. Target
 * Areas is being reviewed under its own PDR (keep / redesign / retire).
 */
class NearbyClientsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_a_business_developer_can_read_the_prospect_pool(): void
    {
        $this->actingAsRole('crm_bd');
        $this->getJson('/api/v1/crm/nearby-clients')->assertOk();
    }

    public function test_a_super_admin_can_read_the_prospect_pool(): void
    {
        $this->actingAsRole('super_admin');
        $this->getJson('/api/v1/crm/nearby-clients')->assertOk();
    }

    /** The behaviour change of R-2: Admin is no longer a Nearby Clients role. */
    public function test_an_admin_is_refused(): void
    {
        $this->actingAsRole('crm_admin');
        $this->getJson('/api/v1/crm/nearby-clients')->assertForbidden();
    }

    public function test_the_prospect_pool_requires_authentication(): void
    {
        $this->getJson('/api/v1/crm/nearby-clients')->assertUnauthorized();
    }

    /**
     * Admin keeps the Target Service Area registry — the capability the Settings relocation exists to
     * preserve. If this ever fails, the CRM Manager has been locked out of their own configuration.
     */
    public function test_admin_retains_target_service_area_management(): void
    {
        $this->actingAsRole('crm_admin');

        $this->getJson('/api/v1/crm/service-areas')->assertOk();
        $id = $this->postJson('/api/v1/crm/service-areas', ['name' => 'Cebu City', 'level' => 'city'])
            ->assertCreated()
            ->json('data.id');
        $this->deleteJson("/api/v1/crm/service-areas/{$id}")->assertNoContent();
    }

    /** …and a BD still cannot manage it — R-2 did not widen anyone's access. */
    public function test_a_business_developer_still_cannot_manage_target_service_areas(): void
    {
        $this->actingAsRole('crm_bd');

        $this->getJson('/api/v1/crm/service-areas')->assertForbidden();
        $this->postJson('/api/v1/crm/service-areas', ['name' => 'Davao', 'level' => 'city'])->assertForbidden();
    }

    /** Qualifying a prospect into the CRM is a BD action and is unchanged by R-2. */
    public function test_prospect_qualification_is_unaffected(): void
    {
        $bd = $this->actingAsRole('crm_bd');
        $prospect = Lead::factory()->create([
            'source' => 'nearby',
            'qualified_at' => null,
            'assigned_to' => null,
            'created_by' => $bd->id,
        ]);

        $this->postJson("/api/v1/crm/leads/{$prospect->id}/qualify")->assertCreated();
    }
}
