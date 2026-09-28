<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Clearing a lead's owner — PATCH /leads/{lead}/assign with `assigned_to: null`.
 *
 * Unassigning is a REASSIGNMENT to nobody, not a new operation: same endpoint, same policy, same
 * service, same `lead.assigned` Activity. These tests pin that equivalence, and pin the two things
 * that must not follow from it — a weaker authorization path, and a way across the tenant fence.
 */
class LeadUnassignTest extends TestCase
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

    private function assign(Lead $lead, ?int $assignedTo)
    {
        return $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $assignedTo]);
    }

    // ── 1. BD → another BD still works ───────────────────────────────────────────────────────

    public function test_reassigning_from_one_business_developer_to_another_still_works(): void
    {
        $from = $this->userWithRole('crm_bd');
        $to = $this->userWithRole('crm_bd');
        Sanctum::actingAs($this->userWithRole('crm_admin'));
        $lead = Lead::factory()->create(['assigned_to' => $from->id]);

        $this->assign($lead, $to->id)
            ->assertOk()
            ->assertJsonPath('data.assignedTo.id', (string) $to->id);

        $this->assertSame($to->id, $lead->fresh()->assigned_to);
    }

    // ── 2. BD → Unassigned clears it ─────────────────────────────────────────────────────────

    public function test_clearing_the_owner_unassigns_the_lead(): void
    {
        $bd = $this->userWithRole('crm_bd');
        Sanctum::actingAs($this->userWithRole('crm_admin'));
        $lead = Lead::factory()->create(['assigned_to' => $bd->id]);

        $this->assign($lead, null)
            ->assertOk()
            ->assertJsonPath('data.assignedTo', null);

        $this->assertNull($lead->fresh()->assigned_to, 'The assignment must persist as NULL.');
        // No stand-in owner was invented — not the actor, not a placeholder account.
        $this->assertDatabaseHas('crm_leads', ['id' => $lead->id, 'assigned_to' => null]);
    }

    public function test_clearing_the_owner_is_recorded_as_an_ownership_change(): void
    {
        $bd = $this->userWithRole('crm_bd');
        $admin = $this->userWithRole('crm_admin');
        Sanctum::actingAs($admin);
        $lead = Lead::factory()->create(['assigned_to' => $bd->id]);

        $this->assign($lead, null)->assertOk();

        // Same event as any other handover, so the audit surfaces that read `lead.assigned` see it.
        $activity = $lead->activities()->where('event', 'lead.assigned')->latest('id')->firstOrFail();
        $this->assertNull($activity->properties['assigned_to']);
        $this->assertNull($activity->properties['to_name']);
        $this->assertStringContainsString('removed the owner', $activity->description);
    }

    public function test_clearing_an_already_unassigned_lead_is_a_no_op(): void
    {
        Sanctum::actingAs($this->userWithRole('crm_admin'));
        $lead = Lead::factory()->create(['assigned_to' => null]);

        $this->assign($lead, null)->assertOk();

        $this->assertNull($lead->fresh()->assigned_to);
        // The service short-circuits when the owner is unchanged, so no handover is logged.
        $this->assertSame(0, $lead->activities()->where('event', 'lead.assigned')->count());
    }

    // ── 3. Unassigned → BD still works ───────────────────────────────────────────────────────

    public function test_assigning_an_unassigned_lead_to_a_business_developer_still_works(): void
    {
        $bd = $this->userWithRole('crm_bd');
        Sanctum::actingAs($this->userWithRole('crm_admin'));
        $lead = Lead::factory()->create(['assigned_to' => null]);

        $this->assign($lead, $bd->id)
            ->assertOk()
            ->assertJsonPath('data.assignedTo.id', (string) $bd->id);

        $this->assertSame($bd->id, $lead->fresh()->assigned_to);
    }

    public function test_a_cleared_lead_can_be_assigned_again(): void
    {
        $first = $this->userWithRole('crm_bd');
        $second = $this->userWithRole('crm_bd');
        Sanctum::actingAs($this->userWithRole('crm_admin'));
        $lead = Lead::factory()->create(['assigned_to' => $first->id]);

        $this->assign($lead, null)->assertOk();
        $this->assign($lead, $second->id)->assertOk();

        $this->assertSame($second->id, $lead->fresh()->assigned_to);
    }

    // ── 4. Unassigned is not a way around access restrictions ────────────────────────────────

    public function test_a_business_developer_cannot_unassign_a_lead(): void
    {
        $bd = $this->userWithRole('crm_bd');
        Sanctum::actingAs($bd);
        $lead = Lead::factory()->create(['assigned_to' => $bd->id]);

        // leads.assign is Admin/SA. Clearing must require exactly the authority reassigning does —
        // a BD must not be able to drop a lead they no longer want off their own pipeline.
        $this->assign($lead, null)->assertForbidden();

        $this->assertSame($bd->id, $lead->fresh()->assigned_to);
    }

    public function test_a_business_developer_cannot_unassign_someone_elses_lead(): void
    {
        $owner = $this->userWithRole('crm_bd');
        $other = $this->userWithRole('crm_bd');
        Sanctum::actingAs($other);
        $lead = Lead::factory()->create(['assigned_to' => $owner->id]);

        $this->assign($lead, null)->assertForbidden();

        $this->assertSame($owner->id, $lead->fresh()->assigned_to);
    }

    public function test_the_field_must_be_present_so_it_cannot_be_cleared_by_omission(): void
    {
        $bd = $this->userWithRole('crm_bd');
        Sanctum::actingAs($this->userWithRole('crm_admin'));
        $lead = Lead::factory()->create(['assigned_to' => $bd->id]);

        $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');

        $this->assertSame($bd->id, $lead->fresh()->assigned_to, 'A malformed request must change nothing.');
    }

    public function test_a_supplied_owner_is_still_validated(): void
    {
        $admin = $this->userWithRole('crm_admin');
        Sanctum::actingAs($this->userWithRole('super_admin'));
        $lead = Lead::factory()->create();

        // Nullable did not become unvalidated: an admin still cannot be made a lead owner.
        $this->assign($lead, $admin->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');
    }

    // ── 5. Tenant isolation ──────────────────────────────────────────────────────────────────

    /** @return array{Org, User, User} org, its admin, its BD */
    private function orgWithStaff(string $slug): array
    {
        $org = app(OrgOnboardingService::class)->createOrg(
            "Org {$slug}", "Owner {$slug}", "owner-{$slug}@client.ph", 'password-123', ['crm' => 'full'],
        )['org'];

        $admin = User::factory()->orgless()->create(['status' => 'active', 'name' => "Admin {$slug}"]);
        $admin->assignRole('crm_admin');
        $org->users()->attach($admin->id, ['is_default' => true]);

        $bd = User::factory()->orgless()->create(['status' => 'active', 'name' => "BD {$slug}"]);
        $bd->assignRole('crm_bd');
        $org->users()->attach($bd->id, ['is_default' => true]);

        return [$org, $admin, $bd];
    }

    private function actAs(User $actor): void
    {
        // See NearbyCrossOrgIsolationTest: leaving the base tenant pinned would make the negative
        // case pass because the harness pointed elsewhere, not because tenancy refused anything.
        CurrentOrg::clear();
        Sanctum::actingAs($actor);
    }

    public function test_unassigning_cannot_reach_another_tenants_lead(): void
    {
        [$ourOrg, $ourAdmin, $ourBd] = $this->orgWithStaff('ours');
        [$theirOrg, , $theirBd] = $this->orgWithStaff('theirs');

        $theirLead = CurrentOrg::runAs($theirOrg->id, fn (): Lead => Lead::factory()->create([
            'assigned_to' => $theirBd->id,
        ]));

        $this->actAs($ourAdmin);
        $this->patchJson("/api/v1/crm/leads/{$theirLead->id}/assign", ['assigned_to' => null])
            ->assertNotFound();

        $stillOwned = CurrentOrg::runAs($theirOrg->id, fn (): ?int => $theirLead->fresh()->assigned_to);
        $this->assertSame($theirBd->id, $stillOwned, 'Another tenant\'s lead must keep its owner.');

        // And our own admin can still clear our own lead — the fence refused the tenant, not the op.
        $ourLead = CurrentOrg::runAs($ourOrg->id, fn (): Lead => Lead::factory()->create([
            'assigned_to' => $ourBd->id,
        ]));
        $this->actAs($ourAdmin);
        $this->patchJson("/api/v1/crm/leads/{$ourLead->id}/assign", ['assigned_to' => null])->assertOk();

        $cleared = CurrentOrg::runAs($ourOrg->id, fn (): ?int => $ourLead->fresh()->assigned_to);
        $this->assertNull($cleared);
    }

    public function test_a_lead_cannot_be_assigned_to_another_tenants_business_developer(): void
    {
        [$ourOrg, $ourAdmin, ] = $this->orgWithStaff('ours');
        [, , $theirBd] = $this->orgWithStaff('theirs');

        $ourLead = CurrentOrg::runAs($ourOrg->id, fn (): Lead => Lead::factory()->create());

        $this->actAs($ourAdmin);
        $this->patchJson("/api/v1/crm/leads/{$ourLead->id}/assign", ['assigned_to' => $theirBd->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');
    }
}
