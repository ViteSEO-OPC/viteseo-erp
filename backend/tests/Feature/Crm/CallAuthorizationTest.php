<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Authorization for the Calling Workspace. Visibility follows the LEAD's ownership: a BD may act only on
 * their own leads' calls; a CRM Admin sees all; a Super Admin bypasses every check; an authenticated user
 * without `crm.calls.*` is forbidden; a guest is unauthorized. The last test pins the permission wiring
 * itself (the seeder grant), so the whole chain — permission middleware + CallPolicy + ownership — is
 * enforced server-side regardless of what the UI exposes.
 */
class CallAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function leadOwnedBy(User $bd): Lead
    {
        return Lead::factory()->create(['assigned_to' => $bd->id, 'created_by' => $bd->id]);
    }

    public function test_guest_is_unauthorized(): void
    {
        $lead = $this->leadOwnedBy($this->withRole('crm_bd'));

        $this->getJson("/api/v1/crm/leads/{$lead->id}/calls")->assertUnauthorized();
        $this->postJson("/api/v1/crm/leads/{$lead->id}/calls")->assertUnauthorized();
    }

    public function test_authenticated_user_without_calls_permission_is_forbidden(): void
    {
        $lead = $this->leadOwnedBy($this->withRole('crm_bd'));
        Sanctum::actingAs(User::factory()->create()); // no role → no crm.calls.* permission

        $this->getJson("/api/v1/crm/leads/{$lead->id}/calls")->assertForbidden();
        $this->postJson("/api/v1/crm/leads/{$lead->id}/calls")->assertForbidden();
    }

    public function test_bd_cannot_touch_another_bds_lead_or_call(): void
    {
        $owner = $this->withRole('crm_bd');
        $intruder = $this->withRole('crm_bd');
        $lead = $this->leadOwnedBy($owner);
        $call = Call::factory()->forLead($lead)->byAgent($owner)->create();

        Sanctum::actingAs($intruder);
        $this->getJson("/api/v1/crm/leads/{$lead->id}/calls")->assertForbidden();
        $this->postJson("/api/v1/crm/leads/{$lead->id}/calls")->assertForbidden();
        $this->patchJson("/api/v1/crm/calls/{$call->id}/complete", ['outcome' => 'connected'])->assertForbidden();
        $this->patchJson("/api/v1/crm/calls/{$call->id}/discard")->assertForbidden();
    }

    public function test_bd_can_act_on_their_own_lead_and_call(): void
    {
        $bd = $this->withRole('crm_bd');
        $lead = $this->leadOwnedBy($bd);
        $call = Call::factory()->forLead($lead)->byAgent($bd)->create();

        Sanctum::actingAs($bd);
        $this->getJson("/api/v1/crm/leads/{$lead->id}/calls")->assertOk();
        $this->patchJson("/api/v1/crm/calls/{$call->id}/discard")->assertOk();
    }

    public function test_crm_admin_sees_any_leads_call_history(): void
    {
        $bd = $this->withRole('crm_bd');
        $lead = $this->leadOwnedBy($bd);
        Call::factory()->forLead($lead)->byAgent($bd)->completed()->create();

        Sanctum::actingAs($this->withRole('crm_admin'));
        $this->getJson("/api/v1/crm/leads/{$lead->id}/calls")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_super_admin_can_act_on_any_lead(): void
    {
        $bd = $this->withRole('crm_bd');
        $lead = $this->leadOwnedBy($bd);

        Sanctum::actingAs($this->withRole('super_admin'));
        $this->postJson("/api/v1/crm/leads/{$lead->id}/calls")->assertCreated();
    }

    public function test_calls_permissions_are_granted_to_the_expected_roles(): void
    {
        foreach (['super_admin', 'crm_admin', 'crm_bd'] as $role) {
            $permissions = Role::findByName($role, 'web')->permissions->pluck('name');
            $this->assertTrue($permissions->contains('crm.calls.view'), "{$role} should have crm.calls.view");
            $this->assertTrue($permissions->contains('crm.calls.log'), "{$role} should have crm.calls.log");
        }
    }
}
