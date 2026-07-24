<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Niche;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 🔒 RBAC Hardening — authorization for the Business Niche registry (PO decision 2026-07-24, corrected).
 *
 *   read · create · rename → every CRM role, INCLUDING Business Development. A BD classifies leads as
 *                            they work, so authoring a niche is part of that job.
 *   delete                 → Super Admin + Admin ONLY. Deletion is the destructive, tenant-wide action:
 *                            the registry is shared, so removing a niche affects every lead under it.
 *
 * The registry originally had NO authorization at all — the RBAC audit reproduced that against the
 * running API with a real BD token (create 201 / rename 200 / delete 204). R-1 first closed it by making
 * BD read-only; the PO then corrected the rule to the split above. Only DELETE is asserted forbidden for
 * a BD, and it is asserted explicitly so the destructive path cannot silently reopen.
 *
 * Business rules (built-ins immutable, in-use niches undeletable) are covered separately in
 * {@see NicheRegistryTest} — they are data integrity, not authorization.
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

    /** A BD authors niches as part of classifying leads — create and rename are theirs. */
    public function test_a_business_developer_can_create_and_rename_a_niche(): void
    {
        $this->actingAsRole('crm_bd');

        $id = $this->postJson('/api/v1/crm/niches', ['name' => 'Boutique Fitness'])
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/v1/crm/niches/{$id}", ['name' => 'Boutique Gyms'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Boutique Gyms');

        $this->assertDatabaseHas('crm_niches', ['id' => $id, 'name' => 'Boutique Gyms']);
    }

    /**
     * The one restriction that matters: deletion is tenant-wide and destructive, so it stays with
     * management. A BD must be refused, and the niche must survive the attempt.
     */
    public function test_a_business_developer_cannot_delete_a_niche(): void
    {
        $niche = $this->customNiche();
        $this->actingAsRole('crm_bd');

        $this->deleteJson("/api/v1/crm/niches/{$niche->id}")->assertForbidden();

        $this->assertDatabaseHas('crm_niches', ['id' => $niche->id, 'name' => 'Boutique Fitness']);
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
