<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\CrmAccessService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CRM-scoped suspension — Slice 2A (enforcement).
 *
 * A suspended BD must lose Zerem and NOTHING else. The platform issues one Sanctum token per login,
 * shared by TeamOra (HRIS), Zerem (CRM) and Sprintly (Projects), so the headline property is that
 * ONE token gets 403 from a CRM route and 200 from an HR and a Projects route in the same test.
 *
 * The 403-not-401 assertions are not stylistic. The CRM axios client clears the stored token and
 * redirects to /login on 401, and that token is the shared one — answering 401 would destroy the
 * user's TeamOra and Sprintly sessions as a side effect of a CRM-only suspension.
 */
class CrmSuspensionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private CrmAccessService $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->access = app(CrmAccessService::class);
    }

    private function multiModuleBd(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_bd');
        $user->assignRole('hr_employee');
        $user->assignRole('pm_member');

        return $user->fresh();
    }

    private function admin(string $role = 'crm_admin'): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    // --- Cross-product isolation: the acceptance gate -------------------------

    public function test_a_suspended_user_loses_crm_but_keeps_hr_and_projects(): void
    {
        $bd = $this->multiModuleBd();
        $this->access->suspend($bd);

        // One actor, three products, one request cycle.
        $this->actingAs($bd)->getJson('/api/v1/crm/leads')->assertForbidden();
        $this->actingAs($bd)->getJson('/api/v1/hr/company-profile')->assertOk();
        $this->actingAs($bd)->getJson('/api/v1/projects/users')->assertOk();
    }

    public function test_shared_auth_endpoints_still_work_while_suspended(): void
    {
        $bd = $this->multiModuleBd();
        $this->access->suspend($bd);

        // Losing Zerem must not look like being logged out of the platform.
        $this->actingAs($bd)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_suspension_never_revokes_the_shared_token(): void
    {
        $bd = $this->multiModuleBd();
        $bd->createToken('web');

        $this->access->suspend($bd);

        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $bd->id)->count());
    }

    // --- 403, never 401 ------------------------------------------------------

    public function test_crm_routes_return_403_with_a_machine_readable_code(): void
    {
        $bd = $this->multiModuleBd();
        $this->access->suspend($bd);

        foreach (['/api/v1/crm/leads', '/api/v1/crm/dashboard/kpis', '/api/v1/crm/planner-events'] as $route) {
            $response = $this->actingAs($bd)->getJson($route);
            $response->assertStatus(403);
            $this->assertNotSame(401, $response->getStatusCode(), "{$route} must never answer 401");
            $response->assertJsonPath('code', 'crm_access_suspended');
        }
    }

    public function test_a_suspended_user_cannot_write_to_the_crm(): void
    {
        $bd = $this->multiModuleBd();
        $this->access->suspend($bd);

        // Before enforcement this reached validation (422) — i.e. it was authorized to write.
        $this->actingAs($bd)->postJson('/api/v1/crm/leads', [])->assertForbidden();
    }

    public function test_crm_me_stays_readable_but_not_writable(): void
    {
        $bd = $this->multiModuleBd();
        $this->access->suspend($bd);

        $this->actingAs($bd)->getJson('/api/v1/crm/me')->assertOk();
        $this->actingAs($bd)->patchJson('/api/v1/crm/me', ['name' => 'New Name'])->assertForbidden();
    }

    // --- Active users are unaffected -----------------------------------------

    public function test_an_active_bd_reaches_the_crm_normally(): void
    {
        $this->actingAs($this->multiModuleBd())->getJson('/api/v1/crm/leads')->assertOk();
    }

    public function test_restoring_access_re_enables_crm_routes(): void
    {
        $bd = $this->multiModuleBd();
        $this->access->suspend($bd);
        $this->actingAs($bd)->getJson('/api/v1/crm/leads')->assertForbidden();

        $this->access->restore($bd);

        // Same account, no re-login, no new token.
        $this->actingAs($bd)->getJson('/api/v1/crm/leads')->assertOk();
    }

    // --- The write path ------------------------------------------------------

    public function test_suspending_through_the_api_does_not_touch_the_shared_row(): void
    {
        $bd = $this->multiModuleBd();
        $before = DB::table('users')->where('id', $bd->id)->first();

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/crm/users/{$bd->id}", ['status' => 'inactive'])
            ->assertOk();

        $this->assertEquals($before, DB::table('users')->where('id', $bd->id)->first());
        $this->assertTrue($this->access->isSuspended($bd->fresh()));
    }

    public function test_suspending_through_the_api_keeps_every_role(): void
    {
        $bd = $this->multiModuleBd();

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/crm/users/{$bd->id}", ['status' => 'inactive'])
            ->assertOk();

        $names = $bd->fresh()->getRoleNames()->all();
        sort($names);
        $this->assertSame(['crm_bd', 'hr_employee', 'pm_member'], $names);
    }

    public function test_restoring_through_the_api_clears_the_suspension(): void
    {
        $bd = $this->multiModuleBd();
        $this->access->suspend($bd);

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/crm/users/{$bd->id}", ['status' => 'active'])
            ->assertOk();

        $this->assertFalse($this->access->isSuspended($bd->fresh()));
    }

    public function test_the_team_accounts_payload_reports_crm_suspension(): void
    {
        $bd = $this->multiModuleBd();
        $this->access->suspend($bd);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/crm/users')
            ->assertOk()
            // Still listed (role intact) so an Admin can recognise the former owner and reactivate.
            ->assertJsonFragment(['id' => $bd->id, 'status' => 'inactive', 'role' => 'crm_bd']);
    }

    public function test_an_admin_cannot_suspend_themselves(): void
    {
        $admin = $this->admin('super_admin');

        $this->actingAs($admin)
            ->patchJson("/api/v1/crm/users/{$admin->id}", ['status' => 'inactive'])
            ->assertStatus(422);

        $this->assertFalse($this->access->isSuspended($admin->fresh()));
    }

    public function test_a_super_admin_account_cannot_be_suspended(): void
    {
        $owner = $this->admin('super_admin');

        $this->actingAs($this->admin('super_admin'))
            ->patchJson("/api/v1/crm/users/{$owner->id}", ['status' => 'inactive'])
            ->assertStatus(422);

        $this->assertFalse($this->access->isSuspended($owner->fresh()));
    }

    // --- Destination guard ---------------------------------------------------

    public function test_a_lead_cannot_be_assigned_to_a_suspended_bd(): void
    {
        $suspended = $this->multiModuleBd();
        $this->access->suspend($suspended);
        $lead = Lead::factory()->create(['assigned_to' => null]);

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $suspended->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assigned_to');

        $this->assertNull($lead->fresh()->assigned_to);
    }

    public function test_an_active_bd_can_still_receive_a_lead(): void
    {
        $bd = $this->multiModuleBd();
        $lead = Lead::factory()->create(['assigned_to' => null]);

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $bd->id])
            ->assertOk();

        $this->assertSame($bd->id, $lead->fresh()->assigned_to);
    }

    public function test_a_suspended_bd_cannot_receive_a_planner_event(): void
    {
        $suspended = $this->multiModuleBd();
        $this->access->suspend($suspended);

        $this->actingAs($this->admin())
            ->postJson('/api/v1/crm/planner-events', [
                'title' => 'Handover call',
                'event_type' => 'call',
                'assigned_to' => $suspended->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assigned_to');
    }

    // --- Forward-looking selection excludes suspended BDs --------------------

    public function test_a_suspended_bd_drops_out_of_bd_performance(): void
    {
        $bd = $this->multiModuleBd();
        $this->access->suspend($bd);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/crm/dashboard/bd-performance')
            ->assertOk()
            ->assertJsonMissing(['id' => (string) $bd->id]);
    }
}
