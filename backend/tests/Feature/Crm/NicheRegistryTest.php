<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Niche;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Business Niche registry endpoints — the vocabulary behind the shared Business Niche selector.
 *
 * The two data-integrity rules are the point of these tests: a built-in niche is immutable, and a
 * niche still used by any lead cannot be deleted. Both are enforced server-side so they hold even
 * when the API is called directly, not only when the modal disables a button.
 *
 * AUTHORIZATION (🔒 PO decision 2026-07-24 — niches are administrative data): Super Admin and Admin
 * have full CRUD; a Business Development Manager is READ-ONLY. Covered in {@see NicheAuthorizationTest};
 * the management cases here therefore act as an Admin, which is who performs them in practice.
 */
class NicheRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** The registry's manager — these cases exercise business rules, not authorization. */
    private function actingAsCrmUser(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_index_lists_built_ins_before_custom_each_alphabetically(): void
    {
        $this->actingAsCrmUser();

        Niche::create(['name' => 'Zebra Care', 'is_custom' => true]);
        Niche::create(['name' => 'Automotive', 'is_custom' => true]);
        Niche::create(['name' => 'Retail', 'is_custom' => false]);
        Niche::create(['name' => 'Education', 'is_custom' => false]);

        $names = $this->getJson('/api/v1/crm/niches')
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['Education', 'Retail', 'Automotive', 'Zebra Care'], $names);
    }

    public function test_index_reports_lead_counts_case_insensitively_excluding_archived(): void
    {
        $this->actingAsCrmUser();
        Niche::create(['name' => 'Retail', 'is_custom' => true]);

        // Different casing than the registry entry — legacy imported rows must still be counted.
        Lead::factory()->create(['industry' => 'retail']);
        Lead::factory()->create(['industry' => 'RETAIL']);
        // Archived leads are excluded from the displayed count (but still block deletion — below).
        Lead::factory()->create(['industry' => 'Retail'])->delete();

        $this->getJson('/api/v1/crm/niches')
            ->assertOk()
            ->assertJsonPath('data.0.leadCount', 2);
    }

    public function test_it_creates_a_custom_niche(): void
    {
        $this->actingAsCrmUser();

        $this->postJson('/api/v1/crm/niches', ['name' => '  Automotive  '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Automotive')
            ->assertJsonPath('data.isCustom', true)
            ->assertJsonPath('data.leadCount', 0);

        $this->assertDatabaseHas('crm_niches', ['name' => 'Automotive', 'is_custom' => true]);
    }

    public function test_it_rejects_a_duplicate_niche_regardless_of_casing(): void
    {
        $this->actingAsCrmUser();
        Niche::create(['name' => 'Retail', 'is_custom' => true]);

        $this->postJson('/api/v1/crm/niches', ['name' => 'retail'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, Niche::query()->count());
    }

    public function test_renaming_a_custom_niche_carries_its_leads_with_it(): void
    {
        $this->actingAsCrmUser();
        $niche = Niche::create(['name' => 'Food and Bev', 'is_custom' => true]);
        $lead = Lead::factory()->create(['industry' => 'Food and Bev']);
        $archived = Lead::factory()->create(['industry' => 'food and bev']);
        $archived->delete();
        $untouched = Lead::factory()->create(['industry' => 'Retail']);

        $this->patchJson("/api/v1/crm/niches/{$niche->id}", ['name' => 'Food & Beverage'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Food & Beverage');

        // The rename is a true rename: live AND archived leads follow, so restoring an archived
        // lead never resurrects a niche value that no longer exists in the registry.
        $this->assertSame('Food & Beverage', $lead->refresh()->industry);
        $this->assertSame('Food & Beverage', $archived->refresh()->industry);
        $this->assertSame('Retail', $untouched->refresh()->industry);
    }

    public function test_a_built_in_niche_cannot_be_renamed(): void
    {
        $this->actingAsCrmUser();
        $niche = Niche::create(['name' => 'Retail', 'is_custom' => false]);

        $this->patchJson("/api/v1/crm/niches/{$niche->id}", ['name' => 'Retail Stores'])
            ->assertForbidden();

        $this->assertSame('Retail', $niche->refresh()->name);
    }

    public function test_it_deletes_a_custom_niche_that_no_lead_uses(): void
    {
        $this->actingAsCrmUser();
        $niche = Niche::create(['name' => 'Automotive', 'is_custom' => true]);

        $this->deleteJson("/api/v1/crm/niches/{$niche->id}")->assertNoContent();

        $this->assertDatabaseMissing('crm_niches', ['id' => $niche->id]);
    }

    public function test_a_built_in_niche_cannot_be_deleted(): void
    {
        $this->actingAsCrmUser();
        $niche = Niche::create(['name' => 'Retail', 'is_custom' => false]);

        $this->deleteJson("/api/v1/crm/niches/{$niche->id}")->assertForbidden();

        $this->assertDatabaseHas('crm_niches', ['id' => $niche->id]);
    }

    public function test_a_niche_still_assigned_to_a_lead_cannot_be_deleted(): void
    {
        $this->actingAsCrmUser();
        $niche = Niche::create(['name' => 'Retail', 'is_custom' => true]);
        Lead::factory()->create(['industry' => 'retail']);

        $this->deleteJson("/api/v1/crm/niches/{$niche->id}")->assertConflict();

        $this->assertDatabaseHas('crm_niches', ['id' => $niche->id]);
    }

    public function test_a_niche_used_only_by_an_archived_lead_cannot_be_deleted(): void
    {
        $this->actingAsCrmUser();
        $niche = Niche::create(['name' => 'Retail', 'is_custom' => true]);
        Lead::factory()->create(['industry' => 'Retail'])->delete();

        // The displayed lead count is 0, but deleting would strand the lead's niche on restore.
        $this->deleteJson("/api/v1/crm/niches/{$niche->id}")->assertConflict();

        $this->assertDatabaseHas('crm_niches', ['id' => $niche->id]);
    }

    public function test_niche_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/crm/niches')->assertUnauthorized();
        $this->postJson('/api/v1/crm/niches', ['name' => 'Automotive'])->assertUnauthorized();
    }
}
