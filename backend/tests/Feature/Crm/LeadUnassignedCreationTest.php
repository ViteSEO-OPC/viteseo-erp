<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Creating a lead WITHOUT an owning BD.
 *
 * `assigned_to` used to be required for anyone who was not a BD, which left Admin and Super Admin
 * unable to create a lead at all before an owner had been chosen. It is now optional. The tests that
 * matter are the ones proving the surrounding rules did NOT move with it: a BD is still auto-assigned
 * to themselves, a supplied owner is still validated, and nobody gained a permission.
 */
class LeadUnassignedCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Acme Corp',
            'contact_name' => 'Maria Santos',
        ], $overrides);
    }

    public function test_a_super_admin_can_create_a_lead_with_no_business_developer(): void
    {
        Sanctum::actingAs($this->userWithRole('super_admin'));

        $response = $this->postJson('/api/v1/crm/leads', $this->payload());

        $response->assertCreated();
        $response->assertJsonPath('data.assignedTo', null);

        $lead = Lead::firstOrFail();
        $this->assertNull($lead->assigned_to, 'The lead must persist as unassigned.');
    }

    public function test_an_admin_can_create_a_lead_with_no_business_developer(): void
    {
        Sanctum::actingAs($this->userWithRole('crm_admin'));

        $this->postJson('/api/v1/crm/leads', $this->payload())->assertCreated();

        $this->assertNull(Lead::firstOrFail()->assigned_to);
    }

    public function test_an_explicit_null_is_accepted(): void
    {
        Sanctum::actingAs($this->userWithRole('crm_admin'));

        // The wizard omits the key; a client that sends it explicitly must not be treated differently.
        $this->postJson('/api/v1/crm/leads', $this->payload(['assigned_to' => null]))
            ->assertCreated();

        $this->assertNull(Lead::firstOrFail()->assigned_to);
    }

    public function test_an_unassigned_lead_survives_a_read_back(): void
    {
        Sanctum::actingAs($this->userWithRole('crm_admin'));

        $id = $this->postJson('/api/v1/crm/leads', $this->payload())->json('data.id');

        $this->getJson("/api/v1/crm/leads/{$id}")
            ->assertOk()
            ->assertJsonPath('data.assignedTo', null);

        $this->getJson('/api/v1/crm/leads')
            ->assertOk()
            ->assertJsonPath('data.0.assignedTo', null);
    }

    // ── What must NOT have changed ───────────────────────────────────────────────────────────

    public function test_assigning_a_business_developer_still_works(): void
    {
        $bd = $this->userWithRole('crm_bd');
        Sanctum::actingAs($this->userWithRole('crm_admin'));

        $this->postJson('/api/v1/crm/leads', $this->payload(['assigned_to' => $bd->id]))
            ->assertCreated()
            ->assertJsonPath('data.assignedTo.id', (string) $bd->id);

        $this->assertSame($bd->id, Lead::firstOrFail()->assigned_to);
    }

    public function test_a_business_developer_is_still_auto_assigned_to_their_own_lead(): void
    {
        $bd = $this->userWithRole('crm_bd');
        Sanctum::actingAs($bd);

        $this->postJson('/api/v1/crm/leads', $this->payload())->assertCreated();

        // Optional `assigned_to` must not become a way for a BD to create an unowned lead.
        $this->assertSame($bd->id, Lead::firstOrFail()->assigned_to);
    }

    public function test_a_business_developer_cannot_create_a_lead_owned_by_someone_else(): void
    {
        $other = $this->userWithRole('crm_bd');
        $bd = $this->userWithRole('crm_bd');
        Sanctum::actingAs($bd);

        $this->postJson('/api/v1/crm/leads', $this->payload(['assigned_to' => $other->id]))
            ->assertCreated();

        // LeadCreator overrides the submitted owner for a BD creator — unchanged by this work.
        $this->assertSame($bd->id, Lead::firstOrFail()->assigned_to);
    }

    public function test_a_supplied_owner_must_still_be_a_business_developer(): void
    {
        $admin = $this->userWithRole('crm_admin');
        Sanctum::actingAs($this->userWithRole('super_admin'));

        // Optional does not mean unvalidated: an admin is not a lead owner.
        $this->postJson('/api/v1/crm/leads', $this->payload(['assigned_to' => $admin->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');

        $this->assertSame(0, Lead::count());
    }

    public function test_a_supplied_owner_must_exist(): void
    {
        Sanctum::actingAs($this->userWithRole('super_admin'));

        $this->postJson('/api/v1/crm/leads', $this->payload(['assigned_to' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');
    }

    public function test_the_other_required_fields_are_still_required(): void
    {
        Sanctum::actingAs($this->userWithRole('super_admin'));

        $this->postJson('/api/v1/crm/leads', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['business_name', 'contact_name']);
    }
}
