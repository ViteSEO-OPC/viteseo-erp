<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Niche;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 🔒 RBAC Hardening R-1 — authorization for the Business Niche registry (PO decision 2026-07-24).
 *
 * Niches are ADMINISTRATIVE data: Super Admin and Admin have full CRUD; a Business Development Manager
 * is READ-ONLY. The registry is tenant-wide, so an unauthorized rename or delete re-segments every lead
 * filed under that niche.
 *
 * This regression exists because the RBAC audit REPRODUCED the gap: with a genuine BD token the API
 * returned 201 on create, 200 on rename and 204 on delete. Every one of those is asserted 403 below, so
 * the hole cannot silently reopen. Business rules (built-ins immutable, in-use niches undeletable) are
 * covered separately in {@see NicheRegistryTest} — they are data integrity, not authorization.
 */
class NicheAuthorizationTest extends TestCase
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

    private function customNiche(): Niche
    {
        return Niche::create(['name' => 'Boutique Fitness', 'is_custom' => true]);
    }

    /** READ is open to every CRM role — a BD needs the vocabulary to classify leads. */
    public function test_every_crm_role_can_read_the_registry(): void
    {
        foreach (['crm_bd', 'crm_admin', 'super_admin'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/v1/crm/niches')->assertOk();
        }
    }

    /**
     * The exact three calls the audit exploited as a BD. All must now be refused.
     */
    public function test_a_business_developer_cannot_mutate_the_registry(): void
    {
        $niche = $this->customNiche();
        $this->actingAsRole('crm_bd');

        $this->postJson('/api/v1/crm/niches', ['name' => 'RBAC Probe'])->assertForbidden();
        $this->patchJson("/api/v1/crm/niches/{$niche->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/crm/niches/{$niche->id}")->assertForbidden();

        // Nothing leaked through: the registry is untouched.
        $this->assertDatabaseHas('crm_niches', ['id' => $niche->id, 'name' => 'Boutique Fitness']);
        $this->assertDatabaseMissing('crm_niches', ['name' => 'RBAC Probe']);
    }

    public function test_an_admin_has_full_crud(): void
    {
        $this->actingAsRole('crm_admin');

        $id = $this->postJson('/api/v1/crm/niches', ['name' => 'Coworking Spaces'])
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/v1/crm/niches/{$id}", ['name' => 'Coworking'])->assertOk();
        $this->deleteJson("/api/v1/crm/niches/{$id}")->assertNoContent();
    }

    public function test_a_super_admin_has_full_crud(): void
    {
        $this->actingAsRole('super_admin');

        $id = $this->postJson('/api/v1/crm/niches', ['name' => 'Marine Services'])
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/v1/crm/niches/{$id}", ['name' => 'Marine'])->assertOk();
        $this->deleteJson("/api/v1/crm/niches/{$id}")->assertNoContent();
    }

    /** Authentication is still the outer gate — an anonymous caller never reaches authorization. */
    public function test_the_registry_requires_authentication(): void
    {
        $niche = $this->customNiche();

        $this->getJson('/api/v1/crm/niches')->assertUnauthorized();
        $this->postJson('/api/v1/crm/niches', ['name' => 'X'])->assertUnauthorized();
        $this->patchJson("/api/v1/crm/niches/{$niche->id}", ['name' => 'X'])->assertUnauthorized();
        $this->deleteJson("/api/v1/crm/niches/{$niche->id}")->assertUnauthorized();
    }
}
