<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The reporting scope on the shared Performance endpoints (performance-summary, kpi-metrics, activities).
 * The security contract: the default is always the requester's own scope; drilling into a specific BD via
 * `?user_id` is management-only (`crm.reports.view`) and returns exactly what that BD sees of themselves —
 * the reuse guarantee that lets Reports & Performance ride the same computation with no duplicated logic.
 */
class PerformanceScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_drilling_into_a_bd_gets_exactly_that_bds_own_view(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');

        // Portfolio for $bd (assigned + converted via a deal), plus noise on $other.
        $won = Lead::factory()->create(['assigned_to' => $bd->id, 'created_by' => $bd->id]);
        $won->deals()->create(['amount' => 5000, 'created_by' => $bd->id]);
        Lead::factory()->count(2)->create(['assigned_to' => $bd->id, 'created_by' => $bd->id]);
        Lead::factory()->count(4)->create(['assigned_to' => $other->id, 'created_by' => $other->id]);

        // What the BD sees of themselves.
        Sanctum::actingAs($bd);
        $bdSelf = $this->getJson('/api/v1/crm/dashboard/performance-summary')->assertOk()->json('data');
        $this->assertSame(3, $bdSelf['assignedLeads']);
        $this->assertSame(1, $bdSelf['convertedLeads']);

        // Admin drilling into that BD gets the identical payload — and NOT the tenant-wide aggregate.
        Sanctum::actingAs($admin);
        $drill = $this->getJson("/api/v1/crm/dashboard/performance-summary?user_id={$bd->id}")->assertOk()->json('data');
        $this->assertSame($bdSelf, $drill);

        $all = $this->getJson('/api/v1/crm/dashboard/performance-summary')->assertOk()->json('data');
        $this->assertSame(7, $all['assignedLeads']); // 3 + 4 across both BDs
    }

    public function test_bd_cannot_pass_user_id_and_is_always_self_scoped(): void
    {
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');
        Sanctum::actingAs($bd);

        // Elevated param from a BD is rejected on every scoped endpoint.
        $this->getJson("/api/v1/crm/dashboard/performance-summary?user_id={$other->id}")->assertForbidden();
        $this->getJson("/api/v1/crm/dashboard/kpi-metrics?user_id={$other->id}")->assertForbidden();
        $this->getJson("/api/v1/crm/activities?user_id={$other->id}")->assertForbidden();

        // Without the param the BD still gets their own scope.
        $this->getJson('/api/v1/crm/dashboard/performance-summary')->assertOk();
    }

    public function test_admin_all_reports_default_needs_no_param(): void
    {
        Sanctum::actingAs($this->user('crm_admin'));
        $this->getJson('/api/v1/crm/dashboard/performance-summary')->assertOk();
        $this->getJson('/api/v1/crm/dashboard/kpi-metrics')->assertOk();
    }

    public function test_report_subject_must_be_an_active_bd(): void
    {
        $admin = $this->user('crm_admin');
        $anotherAdmin = $this->user('crm_admin');
        Sanctum::actingAs($admin);

        // Non-existent id, a non-BD (another admin), and an inactive BD are all 422.
        $this->getJson('/api/v1/crm/dashboard/performance-summary?user_id=999999')
            ->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->getJson("/api/v1/crm/dashboard/performance-summary?user_id={$anotherAdmin->id}")
            ->assertStatus(422)->assertJsonValidationErrors('user_id');

        $inactiveBd = User::factory()->create(['status' => 'inactive']);
        $inactiveBd->assignRole('crm_bd');
        $this->getJson("/api/v1/crm/dashboard/performance-summary?user_id={$inactiveBd->id}")
            ->assertStatus(422)->assertJsonValidationErrors('user_id');
    }
}
